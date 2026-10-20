<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PropertyAbility as A;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Services\Dashboard\PropertyDashboard;
use App\Services\Dashboard\TenantDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Owner and caretaker dashboards (A10). Computed from the same tables as
 * the rest of the app on every request; widgets the viewer may not see are
 * null.
 *
 * @group Dashboards
 */
class DashboardController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly PropertyDashboard $dashboard, private readonly TenantDashboard $tenant) {}

    /**
     * My home (tenant)
     *
     * The signed-in boarder's stay (room, the property's emergency numbers,
     * leaving date), money (owed, overdue and credit in centavos; the next
     * bill), requests (repairs, late entry, overnight visitors), room group
     * (what I owe and am owed; leaders also who owes them), whether new
     * house rules wait, and unread notifications. `stay` and the rest are
     * null when not staying anywhere.
     */
    public function home(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->tenant->for($request->user()));
    }

    /**
     * A property's dashboard
     *
     * Occupancy; due this week and overdue (amounts in centavos); collected
     * this month (owner and Managers); applications and payment proofs
     * waiting; open and urgent repairs; visitors today and requests to
     * decide; rooms with unpaid shared bills.
     */
    public function property(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        return ApiResponse::ok($this->dashboard->for($property, $request->user()));
    }

    /**
     * My properties at a glance
     *
     * Every property the owner or caretaker works on, each with its
     * dashboard, plus (owner only) the five latest activity log entries.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $properties = Property::query()->visibleTo($user)->orderBy('name')->get();

        return ApiResponse::ok([
            'properties' => $properties->map(fn (Property $p) => $this->dashboard->for($p, $user))->values(),
            'recent_activity' => $user->hasAccountRole(UserRole::Owner)
                ? Activity::query()->where('owner_id', $user->id)->latest('id')->limit(5)->get()->map(fn (Activity $a) => new AuditEntryResource($a, false))
                : null,
        ]);
    }
}
