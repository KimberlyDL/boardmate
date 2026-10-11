<?php

namespace Database\Factories;

use App\Enums\BillKind;
use App\Models\Bill;
use App\Models\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bills need a payer at a property; build one with forTenancy().
 *
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => BillKind::Periodic,
            'number' => 'BILL-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'issued_on' => '2026-10-25',
            'due_on' => '2026-10-25',
        ];
    }

    /** The tenant's bill for a tenancy (payer, property, room). */
    public function forTenancy(Tenancy $tenancy): static
    {
        return $this->state(fn () => [
            'property_id' => $tenancy->property_id,
            'payer_id' => $tenancy->tenant_id,
            'tenancy_id' => $tenancy->id,
            'room_id' => $tenancy->room_id,
        ]);
    }
}
