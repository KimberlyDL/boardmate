<?php

namespace App\Enums;

/** Final utility share when a boarder leaves before the last bill (SC-28). */
enum FinalUtilityHandling: string
{
    case NoHoldback = 'no_holdback';
    case HoldbackTrueUp = 'holdback_true_up';
    case EstimateAndClose = 'estimate_and_close';

    public function label(): string
    {
        return match ($this) {
            self::NoHoldback => 'No holdback',
            self::HoldbackTrueUp => 'Holdback + true-up',
            self::EstimateAndClose => 'Estimate and close',
        };
    }
}
