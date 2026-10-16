<?php

namespace Database\Factories;

use App\Enums\ReminderKind;
use App\Models\Bill;
use App\Models\BillReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the bill with forBill().
 *
 * @extends Factory<BillReminder>
 */
class BillReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => ReminderKind::DueSoon,
            'sent_on' => '2026-10-22',
            'balance_centavos' => 180000,
        ];
    }

    public function forBill(Bill $bill): static
    {
        return $this->state(fn () => ['bill_id' => $bill->id, 'payer_id' => $bill->payer_id]);
    }
}
