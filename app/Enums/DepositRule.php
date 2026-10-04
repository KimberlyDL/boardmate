<?php

namespace App\Enums;

/** How the deposit amount is set (default: one month's rent). */
enum DepositRule: string
{
    case OneMonthRent = 'one_month_rent';
    case FixedAmount = 'fixed_amount';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::OneMonthRent => "One month's rent",
            self::FixedAmount => 'Fixed amount',
            self::None => 'No deposit',
        };
    }
}
