<?php

namespace App\Enums;

/** What a move-in payment is for: the deposit, or the advance rent for the first period. */
enum TenancyPaymentKind: string
{
    case Deposit = 'deposit';
    case FirstRent = 'first_rent';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::FirstRent => 'First rent (advance)',
        };
    }
}
