<?php

namespace App\Enums;

/**
 * A tenancy runs month to month from move-in (System Design C1):
 * Active → Notice given (an end date is approved) → Ended (move-out recorded)
 * → Settled (deposit settled, C8). A booking application is the reservation,
 * so there is no Reserved state here.
 */
enum TenancyStatus: string
{
    case Active = 'active';
    case NoticeGiven = 'notice_given';
    case Ended = 'ended';
    case Settled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::NoticeGiven => 'Leaving',
            self::Ended => 'Moved out',
            self::Settled => 'Settled',
        };
    }

    /** Still living there (or due to leave): billed, counted in the room. */
    public function isCurrent(): bool
    {
        return in_array($this, [self::Active, self::NoticeGiven], true);
    }

    /** @return list<string> */
    public static function currentValues(): array
    {
        return [self::Active->value, self::NoticeGiven->value];
    }
}
