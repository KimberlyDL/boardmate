<?php

namespace Database\Factories;

use App\Enums\GroupRole;
use App\Models\ContributionGroup;
use App\Models\GroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the group with forGroup().
 *
 * @extends Factory<GroupMember>
 */
class GroupMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => GroupRole::Member,
            'joined_on' => '2026-09-01',
        ];
    }

    public function forGroup(ContributionGroup $group): static
    {
        return $this->state(fn () => ['group_id' => $group->id]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => GroupRole::Admin]);
    }
}
