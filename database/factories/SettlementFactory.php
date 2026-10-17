<?php

namespace Database\Factories;

use App\Models\Settlement;
use App\Models\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the tenancy with forTenancy().
 *
 * @extends Factory<Settlement>
 */
class SettlementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deposit_held_centavos' => 400000,
            'refund_centavos' => 400000,
        ];
    }

    public function forTenancy(Tenancy $tenancy): static
    {
        return $this->state(fn () => ['tenancy_id' => $tenancy->id]);
    }
}
