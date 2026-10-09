<?php

namespace App\Http\Resources\Tenancies;

use App\Models\RoomOccupant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An occupant. The emergency contact is shown to staff only: the controller
 * sets the `show_emergency_contact` request attribute for them, never for the
 * leader or other members.
 *
 * @mixin RoomOccupant
 */
class RoomOccupantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->attributes->get('show_emergency_contact', false);

        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'name' => $this->name,
            'contact_phone' => $this->contact_phone,
            'has_account' => $this->user_id !== null,
            'is_leader_holder' => $this->tenancy_id !== null,
            'joined_on' => $this->joined_on->toDateString(),
            'left_on' => $this->left_on?->toDateString(),
            'is_staying' => $this->left_on === null,
            $this->mergeWhen($staff, [
                'emergency_contact' => [
                    'name' => $this->emergency_contact_name,
                    'relationship' => $this->emergency_contact_relationship,
                    'phone' => $this->emergency_contact_phone,
                ],
            ]),
        ];
    }
}
