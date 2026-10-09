<?php

namespace Database\Factories;

use App\Models\RentableUnit;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Units have no factory; build one with makeProperty() and pass it to forUnit().
 *
 * @extends Factory<Tenancy>
 */
class TenancyFactory extends Factory
{
    public function definition(): array
    {
        $movedIn = Carbon::today('Asia/Manila');

        return [
            'tenant_id' => User::factory(),
            'status' => 'active',
            'moved_in_on' => $movedIn->toDateString(),
            'anchor_day' => $movedIn->day,
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_relationship' => 'Parent',
            'emergency_contact_phone' => '09171234567',
        ];
    }

    /** Sets the property, room and unit from a unit. */
    public function forUnit(RentableUnit $unit): static
    {
        return $this->state(fn () => [
            'property_id' => $unit->property_id,
            'room_id' => $unit->room_id,
            'unit_id' => $unit->id,
        ]);
    }

    public function movedInOn(string $date): static
    {
        return $this->state(fn () => [
            'moved_in_on' => $date,
            'anchor_day' => (int) Carbon::parse($date)->day,
        ]);
    }
}
