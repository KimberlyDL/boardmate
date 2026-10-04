<?php

namespace App\Enums;

/** Credit from a corrected bill (SC-14). */
enum CorrectionCreditHandling: string
{
    case RollForward = 'roll_forward';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::RollForward => 'Roll forward to the next bill',
            self::Refund => 'Refund',
        };
    }
}
