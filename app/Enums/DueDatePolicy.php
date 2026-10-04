<?php

namespace App\Enums;

/** When rent is due (Billing guide S5, "Due-date policy"). */
enum DueDatePolicy: string
{
    /** Each boarder's due date comes from their move-in date (default). */
    case Anniversary = 'anniversary';
    /** Everyone pays on one date; a newcomer's first period is partial (SC-21). */
    case Common = 'common';
    /** Rent on the anniversary, utilities on a common date. */
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Anniversary => 'Each boarder\'s move-in date',
            self::Common => 'One date for everyone',
            self::Hybrid => 'Rent on move-in date, utilities on one date',
        };
    }
}
