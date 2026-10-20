<?php

namespace App\Http\Controllers\Api\V1\HouseOperations;

use App\Authorization\PropertyAccess;
use App\Enums\PropertyAbility as A;
use App\Enums\VisitorStatus;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Models\Tenancy;
use App\Models\Visitor;
use App\Services\HouseOperations\HouseOpsNotifier;
use App\Services\HouseOperations\VisitorService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Visitors (D4). Tenants register visits ahead; the owner and any caretaker
 * approve overnight stays, log walk-ins and check visitors in and out.
 *
 * @group Visitors
 */
class VisitorController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly VisitorService $visitors, private readonly HouseOpsNotifier $notify) {}

    /**
     * A stay's visitors
     *
     * For the tenant, or staff of the property. Newest visit first.
     * `meta.nights`: overnight nights used this month and the limit.
     */
    public function forStay(Request $request, Tenancy $tenancy): JsonResponse
    {
        if ($tenancy->tenant_id !== $request->user()->id) {
            abort_unless(PropertyAccess::can($request->user(), A::HandleHouseOperations, $tenancy->property), 404);
        }
        $items = Visitor::where('tenancy_id', $tenancy->id)->with(['tenancy.tenant:id,name', 'tenancy.room', 'decider:id,name'])
            ->orderByDesc('visit_on')->orderByDesc('id')->limit(100)->get();
        $settings = $tenancy->property->settings ?? new PropertySettings(PropertySettings::DEFAULTS);

        return ApiResponse::ok($items->map(fn (Visitor $v) => $this->present($v, $request)), meta: ['nights' => [
            'used' => $this->visitors->nightsUsed($tenancy, ManilaDate::today()),
            'limit' => (int) $settings->overnight_limit_per_month,
            'needs_approval' => (bool) $settings->overnight_requires_approval,
        ]]);
    }

    /**
     * Register a visitor
     *
     * The tenant: `name` (contact optional), `visit_on` (today up to 30 days
     * ahead), optional `expected_in` / `expected_out` (HH:MM), and for a stay
     * over `overnight` with `nights` (1–7).
     */
    public function store(Request $request, Tenancy $tenancy): JsonResponse
    {
        abort_unless($tenancy->tenant_id === $request->user()->id, 404);
        $data = $request->validate($this->visitRules() + [
            'visit_on' => ['required', 'date_format:Y-m-d'],
            'expected_in' => ['nullable', 'date_format:H:i'],
            'expected_out' => ['nullable', 'date_format:H:i'],
        ]);
        $visitor = $this->visitors->register($tenancy->loadMissing('property.settings'), $request->user(), $data);
        if ($visitor->status === VisitorStatus::Pending) {
            $this->notify->overnightRequested($visitor, $request->user());
        } elseif ($visitor->over_limit) {
            $this->notify->overLimit($visitor);
        }

        return ApiResponse::created($this->present($visitor, $request), match (true) {
            $visitor->status === VisitorStatus::Pending => 'Registered. The overnight stay waits for approval.',
            $visitor->over_limit => 'Registered. This goes over the overnight limit, so the owner is told.',
            default => 'Visitor registered.',
        });
    }

    /**
     * Cancel a visit
     *
     * The tenant, before the visitor arrives.
     */
    public function cancel(Request $request, Visitor $visitor): JsonResponse
    {
        abort_unless($visitor->tenancy->tenant_id === $request->user()->id, 404);

        return ApiResponse::ok($this->present($this->visitors->cancel($visitor), $request), 'Visit cancelled.');
    }

    /**
     * A property's visitors
     *
     * `view`: today (default: today's visits and anyone still checked in),
     * pending (overnight stays to decide) or all (newest first).
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::HandleHouseOperations);
        $data = $request->validate(['view' => ['nullable', Rule::in(['today', 'pending', 'all'])]]);
        $today = ManilaDate::today()->toDateString();

        $items = Visitor::where('property_id', $property->id)
            ->when(($data['view'] ?? 'today') === 'today', fn ($q) => $q->where(fn ($w) => $w->whereDate('visit_on', $today)->orWhere('status', VisitorStatus::CheckedIn))->orderBy('visit_on'))
            ->when(($data['view'] ?? null) === 'pending', fn ($q) => $q->where('status', VisitorStatus::Pending)->orderBy('visit_on'))
            ->when(($data['view'] ?? null) === 'all', fn ($q) => $q->orderByDesc('visit_on'))
            ->orderBy('id')->with(['tenancy.tenant:id,name', 'tenancy.room', 'decider:id,name'])->limit(200)->get();

        return ApiResponse::ok($items->map(fn (Visitor $v) => $this->present($v, $request)));
    }

    /**
     * Log a walk-in
     *
     * Staff: someone arrived unregistered. `tenancy_id` (whom they visit),
     * `name`, optional `contact`, `overnight` and `nights`. Checked in at once.
     */
    public function walkIn(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::HandleHouseOperations);
        $data = $request->validate($this->visitRules() + ['tenancy_id' => ['required', 'integer']]);
        $visitor = $this->visitors->walkIn($property->loadMissing('settings'), $request->user(), $data);
        if ($visitor->over_limit) {
            $this->notify->overLimit($visitor);
        }

        return ApiResponse::created($this->present($visitor, $request), 'Walk-in logged and checked in.');
    }

    /**
     * Approve an overnight stay
     */
    public function approve(Request $request, Visitor $visitor): JsonResponse
    {
        $this->authorizeProperty($request, $visitor->property, A::HandleHouseOperations);
        $visitor = $this->visitors->approve($visitor, $request->user());
        $this->notify->visitorDecided($visitor);
        if ($visitor->over_limit) {
            $this->notify->overLimit($visitor);
        }

        return ApiResponse::ok($this->present($visitor, $request), $visitor->over_limit ? 'Approved. It goes over the monthly limit.' : 'Approved.');
    }

    /**
     * Decline an overnight stay
     *
     * With a `reason` the tenant sees.
     */
    public function decline(Request $request, Visitor $visitor): JsonResponse
    {
        $this->authorizeProperty($request, $visitor->property, A::HandleHouseOperations);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $visitor = $this->visitors->decline($visitor, $request->user(), $data['reason']);
        $this->notify->visitorDecided($visitor);

        return ApiResponse::ok($this->present($visitor, $request), 'Declined.');
    }

    /**
     * Check a visitor in
     */
    public function checkIn(Request $request, Visitor $visitor): JsonResponse
    {
        $this->authorizeProperty($request, $visitor->property, A::HandleHouseOperations);

        return ApiResponse::ok($this->present($this->visitors->checkIn($visitor), $request), 'Checked in.');
    }

    /**
     * Check a visitor out
     */
    public function checkOut(Request $request, Visitor $visitor): JsonResponse
    {
        $this->authorizeProperty($request, $visitor->property, A::HandleHouseOperations);

        return ApiResponse::ok($this->present($this->visitors->checkOut($visitor), $request), 'Checked out.');
    }

    /** @return array<string, mixed> */
    private function visitRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:50'],
            'overnight' => ['sometimes', 'boolean'],
            'nights' => ['nullable', 'integer', 'min:1', 'max:'.Visitor::MAX_NIGHTS],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Visitor $v, Request $request): array
    {
        $v->loadMissing(['tenancy.tenant:id,name', 'tenancy.room', 'decider:id,name']);
        $mine = $v->tenancy->tenant_id === $request->user()->id;

        return [
            'id' => $v->id,
            'tenancy_id' => $v->tenancy_id,
            'tenant' => ['id' => $v->tenancy->tenant_id, 'name' => $v->tenancy->tenant?->name],
            'room' => $v->tenancy->room ? ['id' => $v->tenancy->room->id, 'label' => $v->tenancy->room->label()] : null,
            'name' => $v->name,
            'contact' => $v->contact,
            'visit_on' => $v->visit_on->toDateString(),
            'expected_in' => $v->expected_in,
            'expected_out' => $v->expected_out,
            'overnight' => $v->overnight,
            'nights' => $v->nights,
            'status' => $v->status->value,
            'status_label' => $v->status->label(),
            'is_walk_in' => $v->is_walk_in,
            'over_limit' => $v->over_limit,
            'decided_by' => $v->decider?->name,
            'decline_reason' => $v->decline_reason,
            'checked_in_at' => $v->checked_in_at?->toIso8601String(),
            'checked_out_at' => $v->checked_out_at?->toIso8601String(),
            'can' => [
                'decide' => ! $mine && $v->status === VisitorStatus::Pending,
                'check_in' => ! $mine && $v->status->canCheckIn(),
                'check_out' => ! $mine && $v->status === VisitorStatus::CheckedIn,
                'cancel' => $mine && in_array($v->status, [VisitorStatus::Pending, VisitorStatus::Registered, VisitorStatus::Approved], true)
                    && $v->visit_on->gte(ManilaDate::today()),
            ],
        ];
    }
}
