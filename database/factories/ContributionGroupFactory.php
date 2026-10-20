<?php

namespace Database\Factories;

use App\Enums\SplitMethod;
use App\Models\ContributionGroup;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the room with forRoom().
 *
 * @extends Factory<ContributionGroup>
 */
class ContributionGroupFactory extends Factory
{
    public function definition(): array
    {
        return ['default_split_method' => SplitMethod::OccupantDays];
    }

    public function forRoom(Room $room): static
    {
        return $this->state(fn () => ['room_id' => $room->id]);
    }
}
