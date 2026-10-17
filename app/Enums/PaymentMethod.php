<?php

namespace App\Enums;

/** How money reached the owner (recorded by the owner or a caretaker). */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case EWallet = 'e_wallet';
    /** The deposit applied to what is owed at settlement (C8). Never typed in by hand; no receipt. */
    case Deposit = 'deposit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::EWallet => 'E-wallet (GCash, Maya)',
            self::Deposit => 'Deposit applied',
        };
    }

    /** Methods a person records or reports by hand (everything but the deposit). */
    public static function manual(): array
    {
        return [self::Cash, self::BankTransfer, self::EWallet];
    }
}
