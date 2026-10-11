<?php

namespace App\Http\Resources\Properties;

use App\Enums\RentalMode;
use App\Http\Resources\Tenancies\RoomLeaderResource;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A room with its code and units. The caller loads `units` (see
 * PropertyResource and RoomController) and prepares them.
 *
 * @mixin Room
 */
class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $units = $this->relationLoaded('units') ? $this->units : $this->units()->get();

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'code' => $this->code(),
            'number' => $this->number,
            'floor' => $this->floor,
            'door_no' => $this->door_no,
            // The door number when set, else the code: what people call the room.
            'label' => $this->label(),
            'who_can_stay' => $this->who_can_stay->value,
            'who_can_stay_label' => $this->who_can_stay->label(),
            'rental_mode' => $this->rental_mode->value,
            'rental_mode_label' => $this->rental_mode->label(),
            // Rented as one room right now (a whole room, or its whole-room option is held).
            'let_whole' => $this->rental_mode === RentalMode::Whole || $units->contains(fn ($u) => $u->is_whole_option && $u->isInUse()),
            'counts' => [
                'units' => $units->where('is_whole_option', false)->count(),
                'available' => $units->where('is_whole_option', false)->filter->isAvailable()->count(),
            ],
            'units' => UnitResource::collection($units),
            'leader' => $this->whenLoaded('leader', fn () => $this->leader ? new RoomLeaderResource($this->leader) : null),
            // People staying in a room rented whole (the leader included).
            'occupants_count' => $this->whenCounted('occupants'),
        ];
    }
}
