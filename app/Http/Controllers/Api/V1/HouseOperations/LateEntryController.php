<?php

namespace App\Http\Controllers\Api\V1\HouseOperations;

use App\Authorization\PropertyAccess;
use App\Enums\LateEntryStatus;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LateEntryRequest;
use App\Models\Property;
use App\Models\Tenancy;
use App\Services\HouseOperations\HouseOpsNotifier;
use App\Services\HouseOperations\LateEntryService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Late-entry requests (D2). The tenant asks ahead to come back after
 * curfew; the owner or any caretaker approves or declines.
 *
 * @group Curfew
 */
class LateEntryController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly LateEntryService $lateEntries, private readonly HouseOpsNotifier $notify) {}

    /**
     * A stay's late-entry requests
     *
     * For the tenant, or staff of the property. Newest day first.
     */
    public function forStay(Request $request, Tenancy $tenancy): JsonResponse
    {
        if ($tenancy->tenant_id !== $request->user()->id) {
            abort_unless(PropertyAccess::can($request->user(), A::HandleHouseOperations, $tenancy->property), 404);
        }
        $items = LateEntryRequest::where('tenancy_id', $tenancy->id)->with(['decider:id,name', 'tenancy.tenant:id,name', 'tenancy.room'])
            ->orderByDesc('on_date')->orderByDesc('id')->limit(100)->get();

        return ApiResponse::ok($items->map(fn (LateEntryRequest $r) => $this->present($r, $request)));
    }

    /**
     * Ask to come back late
     *
     * `on_date` (today up to 7 days ahead), `expected_arrival` (HH:MM) and a
     * `reason`. Only where the property has a curfew.
     */
    public function store(Request $request, Tenancy $tenancy): JsonResponse
    {
        abort_unless($tenancy->tenant_id === $request->user()->id, 404);
        $data = $request->validate([
            'on_date' => ['required', 'date_format:Y-m-d'],
            'expected_arrival' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $item = $this->lateEntries->request($tenancy, $request->user(), $data);
        $this->notify->lateEntryRequested($item, $request->user());

        return ApiResponse::created($this->present($item->load(['tenancy.tenant:id,name', 'tenancy.room']), $request), 'Request sent. You are told when it is decided.');
    }

    /**
     * Cancel my request
     */
    public function cancel(Request $request, LateEntryRequest $lateEntry): JsonResponse
    {
        abort_unless($lateEntry->tenancy->tenant_id === $request->user()->id, 404);
        $item = $this->lateEntries->cancel($lateEntry);

        return ApiResponse::ok($this->present($item, $request), 'Request cancelled.');
    }

    /**
     * A property's late-entry requests
     *
     * `status`: pending (default, oldest day first), upcoming (approved,
     * today onwards) or all (newest first).
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::HandleHouseOperations);
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'upcoming', 'all'])]]);
        $status = $data['status'] ?? 'pending';
        $today = ManilaDate::today()->toDateString();

        $items = LateEntryRequest::where('property_id', $property->id)
            ->when($status === 'pending', fn ($q) => $q->where('status', LateEntryStatus::Pending)->whereDate('on_date', '>=', $today)->orderBy('on_date'))
            ->when($status === 'upcoming', fn ($q) => $q->where('status', LateEntryStatus::Approved)->whereDate('on_date', '>=', $today)->orderBy('on_date'))
            ->when($status === 'all', fn ($q) => $q->orderByDesc('on_date'))
            ->orderBy('id')->with(['decider:id,name', 'tenancy.tenant:id,name', 'tenancy.room'])->limit(200)->get();

        return ApiResponse::ok($items->map(fn (LateEntryRequest $r) => $this->present($r, $request)));
    }

    /**
     * Approve a request
     */
    public function approve(Request $request, LateEntryRequest $lateEntry): JsonResponse
    {
        $this->authorizeProperty($request, $lateEntry->property, A::HandleHouseOperations);
        $item = $this->lateEntries->approve($lateEntry, $request->user());
        $this->notify->lateEntryDecided($item);

        return ApiResponse::ok($this->present($item, $request), 'Approved.');
    }

    /**
     * Decline a request
     *
     * With a `reason` the tenant sees.
     */
    public function decline(Request $request, LateEntryRequest $lateEntry): JsonResponse
    {
        $this->authorizeProperty($request, $lateEntry->property, A::HandleHouseOperations);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $item = $this->lateEntries->decline($lateEntry, $request->user(), $data['reason']);
        $this->notify->lateEntryDecided($item);

        return ApiResponse::ok($this->present($item, $request), 'Declined.');
    }

    /** @return array<string, mixed> */
    private function present(LateEntryRequest $r, Request $request): array
    {
        $r->loadMissing(['decider:id,name', 'tenancy.tenant:id,name', 'tenancy.room']);
        $mine = $r->tenancy->tenant_id === $request->user()->id;
        $staff = ! $mine;
        $notPast = $r->on_date->gte(ManilaDate::today());

        return [
            'id' => $r->id,
            'tenancy_id' => $r->tenancy_id,
            'tenant' => ['id' => $r->tenancy->tenant_id, 'name' => $r->tenancy->tenant?->name],
            'room' => $r->tenancy->room ? ['id' => $r->tenancy->room->id, 'label' => $r->tenancy->room->label()] : null,
            'on_date' => $r->on_date->toDateString(),
            'expected_arrival' => $r->expected_arrival,
            'reason' => $r->reason,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'decided_by' => $r->decider?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decline_reason' => $r->decline_reason,
            'created_at' => $r->created_at?->toIso8601String(),
            'can' => [
                'decide' => $staff && $r->status === LateEntryStatus::Pending,
                'cancel' => $mine && $r->status->isOpen() && $notPast,
            ],
        ];
    }
}
