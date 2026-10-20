<?php

namespace Database\Factories;

use App\Models\HouseRuleVersion;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the property with forProperty(). A draft by default; published($n)
 * numbers it.
 *
 * @extends Factory<HouseRuleVersion>
 */
class HouseRuleVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['version' => null];
    }

    public function forProperty(Property $property): static
    {
        return $this->state(fn () => ['property_id' => $property->id]);
    }

    public function published(int $version = 1): static
    {
        return $this->state(fn () => ['version' => $version, 'published_at' => now()]);
    }
}
