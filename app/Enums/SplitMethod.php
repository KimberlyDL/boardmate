<?php

namespace App\Enums;

/** How a bill was divided (Billing guide S6, SC-07, SC-12). Saved on the bill. */
enum SplitMethod: string
{
    case Equal = 'equal';
    case Weights = 'weights';
    case OccupantDays = 'occupant_days';
    case Custom = 'custom';
    case FixedPlusRemainder = 'fixed_plus_remainder';
    case WeightedAbsorb = 'weighted_absorb';

    public function label(): string
    {
        return match ($this) {
            self::Equal => 'Equal',
            self::Weights => 'By weight',
            self::OccupantDays => 'By days stayed',
            self::Custom => 'Fixed amounts',
            self::FixedPlusRemainder => 'Fixed + remainder',
            self::WeightedAbsorb => 'By weight, landlord covers the rest',
        };
    }
}
