<?php

namespace Database\Factories;

use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campus>
 */
class CampusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Campus',
            'latitude' => fake()->latitude(14.5, 14.7),
            'longitude' => fake()->longitude(120.95, 121.1),
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
