<?php

namespace App\Enums;

/** What happens when a boarder gives less notice than the minimum (SC-10). */
enum ShortNoticeConsequence: string
{
    case None = 'none';
    case FixedFee = 'fixed_fee';
    case DepositDeduction = 'deposit_deduction';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Nothing (flag only)',
            self::FixedFee => 'Fixed fee',
            self::DepositDeduction => 'Deduct from deposit',
        };
    }
}
