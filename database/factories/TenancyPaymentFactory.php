<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\TenancyPaymentKind;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenancyPayment>
 */
class TenancyPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenancy_id' => Tenancy::factory(),
            'kind' => TenancyPaymentKind::Deposit,
            'amount_centavos' => 400000,
            'method' => PaymentMethod::Cash,
            'received_on' => now('Asia/Manila')->toDateString(),
        ];
    }

    public function firstRent(): static
    {
        return $this->state(fn () => ['kind' => TenancyPaymentKind::FirstRent]);
    }
}
