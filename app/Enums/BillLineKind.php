<?php

namespace App\Enums;

/** One line of a bill. Payments settle rent first, then utilities, then other charges (C6). */
enum BillLineKind: string
{
    case Rent = 'rent';
    case FixedUtility = 'fixed_utility';
    case UtilityShare = 'utility_share';
    case OneTime = 'one_time';
    /** A correction: positive adds to what is owed, negative is a credit. */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::FixedUtility => 'Utility (fixed)',
            self::UtilityShare => 'Utility (actual bill)',
            self::OneTime => 'One-time charge',
            self::Adjustment => 'Adjustment',
        };
    }

    /** Order on the same due date when a payment is applied: lower first. */
    public function settleOrder(): int
    {
        return match ($this) {
            self::Rent => 1,
            self::FixedUtility, self::UtilityShare => 2,
            self::OneTime, self::Adjustment => 3,
        };
    }
}
