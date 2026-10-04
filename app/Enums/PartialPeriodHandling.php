<?php

namespace App\Enums;

/** A newcomer's partial first period under a common due date (SC-21). */
enum PartialPeriodHandling: string
{
    /** Charge rent × days ÷ 30 for the partial period. */
    case Prorated = 'prorated';
    /** One full rent covering the partial period plus the next full one. */
    case FullShifted = 'full_shifted';
    /** Nothing until the first common due date. */
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Prorated => 'Charge only the days stayed',
            self::FullShifted => 'One full rent, longer first period',
            self::Waived => 'Free until the common due date',
        };
    }
}
