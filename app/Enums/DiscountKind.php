<?php

namespace App\Enums;

/** A tenancy's manual rent discount, labelled "Agreed rate" (System Design C3). */
enum DiscountKind: string
{
    /** A fixed amount off the rent, in centavos. */
    case Fixed = 'fixed';

    /** A percentage off the rent, in basis points (1000 = 10%). */
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::Percent => 'Percent',
        };
    }
}
