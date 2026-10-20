<?php

namespace App\Enums;

/**
 * A visitor's state (D4). Daytime visits are Registered; an overnight stay
 * that needs approval waits as Pending until Approved or Declined. Staff
 * check visitors in and out; the tenant may cancel before the visit.
 */
enum VisitorStatus: string
{
    case Pending = 'pending';
    case Registered = 'registered';
    case Approved = 'approved';
    case Declined = 'declined';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Registered => 'Registered',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::CheckedIn => 'Checked in',
            self::CheckedOut => 'Checked out',
            self::Cancelled => 'Cancelled',
        };
    }

    /** May arrive (be checked in). */
    public function canCheckIn(): bool
    {
        return in_array($this, [self::Registered, self::Approved], true);
    }

    /** Overnight nights that count toward the monthly limit. */
    public static function countedValues(): array
    {
        return [self::Registered->value, self::Approved->value, self::CheckedIn->value, self::CheckedOut->value];
    }
}
