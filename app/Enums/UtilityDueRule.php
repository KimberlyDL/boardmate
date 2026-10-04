<?php

namespace App\Enums;

/** When an actual-bill utility charge is due (SC-20). */
enum UtilityDueRule: string
{
    /** N days after the bill is entered (default N = 7). */
    case DaysAfterIssue = 'days_after_issue';
    /** On the boarder's next rent due date. */
    case WithNextRent = 'with_next_rent';

    public function label(): string
    {
        return match ($this) {
            self::DaysAfterIssue => 'A set number of days after the bill is entered',
            self::WithNextRent => 'With the next rent',
        };
    }
}
