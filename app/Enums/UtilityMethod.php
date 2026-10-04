<?php

namespace App\Enums;

/** How a utility is charged (Billing guide S4). Any method works for any utility. */
enum UtilityMethod: string
{
    case FixedPerBedspace = 'fixed_per_bedspace';
    case FixedPerProperty = 'fixed_per_property';
    case ActualBill = 'actual_bill';
    case Included = 'included';
    case OptIn = 'opt_in';

    /** Methods with a set price per period (stored as effective-dated price rules). */
    public function hasFixedAmount(): bool
    {
        return in_array($this, [self::FixedPerBedspace, self::FixedPerProperty, self::OptIn], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::FixedPerBedspace => 'Fixed per bedspace',
            self::FixedPerProperty => 'Fixed per property',
            self::ActualBill => 'Actual bill',
            self::Included => 'Included in rent',
            self::OptIn => 'Opt-in',
        };
    }
}
