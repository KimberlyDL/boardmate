<?php

namespace Database\Factories;

use App\Enums\BillLineKind;
use App\Models\Bill;
use App\Models\BillLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillLine>
 */
class BillLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => BillLineKind::Rent,
            'description' => 'Rent',
            'amount_centavos' => 180000,
        ];
    }

    public function forBill(Bill $bill): static
    {
        return $this->state(fn () => ['bill_id' => $bill->id]);
    }
}
