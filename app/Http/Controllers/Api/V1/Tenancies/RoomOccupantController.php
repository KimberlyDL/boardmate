<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Authorization\RoomAccess;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenancies\RoomOccupantResource;
use App\Http\Responses\ApiResponse;
use App\Models\Room;
use App\Models\RoomOccupant;
use App\Services\Tenancies\RoomOccupantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * People who stay in a room rented whole.
 *
 * @group Room occupants
 */
class RoomOccupantController extends Controller
{
    public function __construct(private readonly RoomOccupantService $occupants) {}

    /**
     * List a room's occupants
     *
     * The owner and the property's caretakers see everyone with their
     * emergency contacts. The leader sees the same list without emergency
     * contacts.
     */
    public function index(Request $request, Room $room): JsonResponse
    {
        abort_unless(RoomAccess::canView($request->user(), $room), 404);
        $this->showEmergencyContactsToStaff($request, $room);

        return ApiResponse::ok(RoomOccupantResource::collection($room->occupants()->get()));
    }

    /**
     * Add an occupant
     *
     * Owner, Manager or the room's leader. Only for a room rented whole that
     * someone has moved into, and never above the room's capacity. `email`
     * optionally links an existing BoardMate account.
     */
    public function store(Request $request, Room $room): JsonResponse
    {
        abort_unless(RoomAccess::canView($request->user(), $room), 404);
        abort_unless(RoomAccess::canManageOccupants($request->user(), $room), 403, 'You do not have permission to do this for this room.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'joined_on' => ['required', 'date_format:Y-m-d'],
            'emergency_contact_name' => ['required', 'string', 'max:120'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
            'emergency_contact_phone' => ['required', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
        ]);

        $occupant = $this->occupants->add($room, $data, $request->user());
        $this->showEmergencyContactsToStaff($request, $room);

        return ApiResponse::created(new RoomOccupantResource($occupant), 'Added.');
    }

    /**
     * Change an occupant
     *
     * Owner or Manager can change everything. The leader can change the name,
     * phone and the leave date only. Setting `left_on` marks the person as
     * having left; clearing it (owner or Manager) brings them back.
     */
    public function update(Request $request, RoomOccupant $occupant): JsonResponse
    {
        $room = $occupant->room;
        abort_unless(RoomAccess::canView($request->user(), $room), 404);
        $staff = RoomAccess::staffCan($request->user(), $room, A::ManageTenancies);
        abort_unless($staff || RoomAccess::isLeader($request->user(), $room), 403, 'You do not have permission to do this for this room.');

        $rules = [
            'name' => ['sometimes', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            // Only staff can clear it (bring someone back); the leader can only set it.
            'left_on' => ['sometimes', $staff ? 'nullable' : 'required', 'date_format:Y-m-d'],
        ];
        if ($staff) {
            $rules += [
                'joined_on' => ['sometimes', 'date_format:Y-m-d'],
                'emergency_contact_name' => ['sometimes', 'string', 'max:120'],
                'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
                'emergency_contact_phone' => ['sometimes', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            ];
        }

        $occupant = $this->occupants->update($occupant, $request->validate($rules), $request->user());
        if ($staff) {
            $request->attributes->set('show_emergency_contact', true);
        }

        return ApiResponse::ok(new RoomOccupantResource($occupant), 'Saved.');
    }

    /**
     * Remove an occupant
     *
     * Owner or Manager only. The person who rents the room cannot be removed.
     */
    public function destroy(Request $request, RoomOccupant $occupant): JsonResponse
    {
        $room = $occupant->room;
        abort_unless(RoomAccess::canView($request->user(), $room), 404);
        abort_unless(RoomAccess::staffCan($request->user(), $room, A::ManageTenancies), 403, 'You do not have permission to do this for this property.');

        $this->occupants->remove($occupant, $request->user());

        return ApiResponse::message('Removed.');
    }

    private function showEmergencyContactsToStaff(Request $request, Room $room): void
    {
        $request->attributes->set('show_emergency_contact', RoomAccess::staffCan($request->user(), $room, A::View));
    }
}
