<?php

namespace App\Http\Controllers\Api\V1\HouseOperations;

use App\Authorization\PropertyAccess;
use App\Enums\LateEntryStatus;
use App\Enums\PropertyAbility as A;
use App\Enums\VisitorStatus;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CurfewViolation;
use App\Models\LateEntryRequest;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Visitor;
use App\Services\HouseOperations\CurfewService;
use App\Services\HouseOperations\HouseOpsNotifier;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Curfew violations and the reminder switch (D2). Staff log late arrivals
 * that had no approved request; it is a log only, and repeated ones are
 * flagged for the owner. Tenants see their own and can mute the reminder.
 *
 * @group Curfew
 */
class CurfewController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly CurfewService $curfew, private readonly HouseOpsNotifier $notify) {}

    /**
     * A property's violation log
     *
     * Newest night first. `meta.repeated`: tenancy id => count, for stays with
     * 3 or more in the last 30 days.
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::HandleHouseOperations);
        $items = CurfewViolation::where('property_id', $property->id)->with(['tenancy.tenant:id,name', 'tenancy.room', 'logger:id,name'])
            ->orderByDesc('on_date')->orderByDesc('id')->limit(200)->get();

        return ApiResponse::ok($items->map(fn (CurfewViolation $v) => $this->present($v, true)), meta: ['repeated' => (object) $this->curfew->repeated($property)]);
    }

    /**
     * What waits at my properties
     *
     * For the owner's and caretakers' home screens: each property with
     * late-entry requests or overnight stays to decide, or stays with
     * repeated curfew violations (3 or more in 30 days).
     */
    public function summary(Request $request): JsonResponse
    {
        $today = ManilaDate::today()->toDateString();
        $rows = Property::query()->visibleTo($request->user())->with('settings')->orderBy('name')->get()
            ->filter(fn (Property $p) => PropertyAccess::can($request->user(), A::HandleHouseOperations, $p))
            ->map(fn (Property $p) => [
                'property_id' => $p->id,
                'property_name' => $p->name,
                'late_entries' => LateEntryRequest::where('property_id', $p->id)->where('status', LateEntryStatus::Pending)->whereDate('on_date', '>=', $today)->count(),
                'overnight' => Visitor::where('property_id', $p->id)->where('status', VisitorStatus::Pending)->count(),
                'repeated' => count($this->curfew->repeated($p)),
            ])
            ->filter(fn ($r) => $r['late_entries'] + $r['overnight'] + $r['repeated'] > 0)
            ->values();

        return ApiResponse::ok($rows);
    }

    /**
     * Log a violation
     *
     * `tenancy_id` (someone staying here now), `on_date` (the night, up to
     * 7 days back), optional `arrived_at` (HH:MM) and `note`. Refused when a
     * late entry that night was approved.
     */
    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::HandleHouseOperations);
        $data = $request->validate([
            'tenancy_id' => ['required', 'integer'],
            'on_date' => ['required', 'date_format:Y-m-d'],
            'arrived_at' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $violation = $this->curfew->logViolation($property->loadMissing('settings'), $data, $request->user());
        $this->notify->violationLogged($violation);

        return ApiResponse::created($this->present($violation->load(['tenancy.tenant:id,name', 'tenancy.room', 'logger:id,name']), true), 'Logged. No fee is added.');
    }

    /**
     * Remove a violation
     *
     * The owner or a Manager, for one logged by mistake.
     */
    public function destroy(Request $request, CurfewViolation $violation): JsonResponse
    {
        $this->authorizeProperty($request, $violation->property, A::ManageTenancies);
        $violation->delete();

        return ApiResponse::ok(null, 'Removed.');
    }

    /**
     * A stay's violations
     *
     * For the tenant, or staff of the property.
     */
    public function forStay(Request $request, Tenancy $tenancy): JsonResponse
    {
        $staff = $tenancy->tenant_id !== $request->user()->id;
        if ($staff) {
            abort_unless(PropertyAccess::can($request->user(), A::HandleHouseOperations, $tenancy->property), 404);
        }
        $items = CurfewViolation::where('tenancy_id', $tenancy->id)->with(['tenancy.tenant:id,name', 'tenancy.room', 'logger:id,name'])
            ->orderByDesc('on_date')->limit(100)->get();

        return ApiResponse::ok($items->map(fn (CurfewViolation $v) => $this->present($v, $staff)));
    }

    /**
     * Mute or unmute the curfew reminder
     *
     * The tenant, for this stay: `muted` true or false.
     */
    public function reminders(Request $request, Tenancy $tenancy): JsonResponse
    {
        abort_unless($tenancy->tenant_id === $request->user()->id, 404);
        $data = $request->validate(['muted' => ['required', 'boolean']]);
        $tenancy->forceFill(['curfew_reminders_muted' => $data['muted']])->save();

        return ApiResponse::ok(['muted' => $tenancy->curfew_reminders_muted], $data['muted'] ? 'Curfew reminders off.' : 'Curfew reminders on.');
    }

    /** @return array<string, mixed> */
    private function present(CurfewViolation $v, bool $staff): array
    {
        return [
            'id' => $v->id,
            'tenancy_id' => $v->tenancy_id,
            'tenant' => ['id' => $v->tenancy->tenant_id, 'name' => $v->tenancy->tenant?->name],
            'room' => $v->tenancy->room ? ['id' => $v->tenancy->room->id, 'label' => $v->tenancy->room->label()] : null,
            'on_date' => $v->on_date->toDateString(),
            'arrived_at' => $v->arrived_at,
            'note' => $v->note,
            'logged_by' => $staff ? $v->logger?->name : null,
            'created_at' => $v->created_at?->toIso8601String(),
        ];
    }
}
