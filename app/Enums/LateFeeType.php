<?php

namespace App\Enums;

/** Optional late fee (S8): off by default. */
enum LateFeeType: string
{
    case Off = 'off';
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Fixed => 'Fixed amount',
            self::Percentage => 'Percentage of the charge',
        };
    }
}
