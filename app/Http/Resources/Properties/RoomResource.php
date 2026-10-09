<?php

namespace App\Http\Resources\Properties;

use App\Http\Resources\Tenancies\RoomLeaderResource;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A room with its code and units. The caller loads `property.building` and
 * `units` (see PropertyResource and RoomController) and prepares the units.
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
            'rental_mode' => $this->rental_mode->value,
            'rental_mode_label' => $this->rental_mode->label(),
            'counts' => [
                'units' => $units->count(),
                'available' => $units->filter->isAvailable()->count(),
            ],
            'units' => UnitResource::collection($units),
            'leader' => $this->whenLoaded('leader', fn () => $this->leader ? new RoomLeaderResource($this->leader) : null),
            // People staying in a room rented whole (the leader included).
            'occupants_count' => $this->whenCounted('occupants'),
        ];
    }
}
