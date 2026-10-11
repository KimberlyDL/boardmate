<?php

namespace Database\Factories;

use App\Models\UtilityAccount;
use App\Models\UtilityBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Accounts have no factory; pass one to forAccount().
 *
 * @extends Factory<UtilityBill>
 */
class UtilityBillFactory extends Factory
{
    public function definition(): array
    {
        return [
            'coverage_from_on' => '2026-09-10',
            'coverage_to_on' => '2026-10-09',
            'total_centavos' => 180000,
        ];
    }

    public function forAccount(UtilityAccount $account): static
    {
        return $this->state(fn () => ['utility_account_id' => $account->id, 'property_id' => $account->property_id]);
    }
}
