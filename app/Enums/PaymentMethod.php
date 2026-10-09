<?php

namespace App\Enums;

/** How money reached the owner (recorded by the owner or a caretaker). */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case EWallet = 'e_wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::EWallet => 'E-wallet (GCash, Maya)',
        };
    }
}
