<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the property and payer with forPayer().
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'amount_centavos' => 180000,
            'method' => PaymentMethod::Cash,
            'paid_on' => '2026-10-25',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function forPayer(Property $property, User $payer): static
    {
        return $this->state(fn () => ['property_id' => $property->id, 'payer_id' => $payer->id]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Confirmed, 'decided_at' => now()]);
    }
}
