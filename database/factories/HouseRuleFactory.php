<?php

namespace Database\Factories;

use App\Enums\HouseRuleCategory;
use App\Models\HouseRule;
use App\Models\HouseRuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the version with forVersion().
 *
 * @extends Factory<HouseRule>
 */
class HouseRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category' => HouseRuleCategory::QuietHours,
            'body' => 'Keep quiet from 10:00 PM to 6:00 AM.',
            'position' => 1,
        ];
    }

    public function forVersion(HouseRuleVersion $version): static
    {
        return $this->state(fn () => ['version_id' => $version->id]);
    }
}
