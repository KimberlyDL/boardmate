<?php

namespace Database\Factories;

use App\Enums\DiscountKind;
use App\Models\Tenancy;
use App\Models\TenancyDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenancyDiscount>
 */
class TenancyDiscountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenancy_id' => Tenancy::factory(),
            'kind' => DiscountKind::Fixed,
            'value' => 50000,
            'effective_from' => now('Asia/Manila')->toDateString(),
        ];
    }

    public function percent(int $basisPoints): static
    {
        return $this->state(fn () => ['kind' => DiscountKind::Percent, 'value' => $basisPoints]);
    }
}
