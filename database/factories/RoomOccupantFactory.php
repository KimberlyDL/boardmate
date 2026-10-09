<?php

namespace Database\Factories;

use App\Models\RoomOccupant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the room with `['room_id' => $room->id]`.
 *
 * @extends Factory<RoomOccupant>
 */
class RoomOccupantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'joined_on' => now('Asia/Manila')->toDateString(),
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_relationship' => 'Sibling',
            'emergency_contact_phone' => '09181234567',
        ];
    }
}
