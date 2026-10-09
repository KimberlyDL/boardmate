<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Authorization\RoomAccess;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenancies\RoomLeaderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Room;
use App\Models\User;
use App\Services\Tenancies\RoomLeaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Room leaders
 */
class RoomLeaderController extends Controller
{
    public function __construct(private readonly RoomLeaderService $leaders) {}

    /**
     * Get a room's leader
     *
     * `data` is null when the room has no leader yet. Visible to the owner,
     * the property's caretakers and the leader.
     */
    public function show(Request $request, Room $room): JsonResponse
    {
        abort_unless(RoomAccess::canView($request->user(), $room), 404);

        $leader = $room->leader()->with('user')->first();

        return ApiResponse::ok($leader ? new RoomLeaderResource($leader) : null);
    }

    /**
     * Appoint or replace the leader
     *
     * Owner or Manager. `user_id` must be someone who lives in the room, and
     * `consent` must be true: the person agreed to be the leader (date and
     * approver are recorded). A room rented whole has the person who rents it
     * as its leader, so it cannot be replaced here.
     */
    public function update(Request $request, Room $room): JsonResponse
    {
        abort_unless(RoomAccess::staffCan($request->user(), $room, A::View), 404);
        abort_unless(RoomAccess::staffCan($request->user(), $room, A::ManageTenancies), 403, 'You do not have permission to do this for this property.');

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'consent' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $leader = $this->leaders->appoint($room, User::findOrFail($data['user_id']), $request->user(), (bool) $data['consent'], $data['reason'] ?? null);

        return ApiResponse::ok(new RoomLeaderResource($leader), 'Leader saved.');
    }
}
