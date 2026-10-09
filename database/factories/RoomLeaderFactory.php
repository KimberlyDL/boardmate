<?php

namespace Database\Factories;

use App\Models\RoomLeader;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the room with `for($room)` or `['room_id' => $room->id]`.
 *
 * @extends Factory<RoomLeader>
 */
class RoomLeaderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'consent_recorded_at' => now(),
            'started_on' => now('Asia/Manila')->toDateString(),
        ];
    }
}
