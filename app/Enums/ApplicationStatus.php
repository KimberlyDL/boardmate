<?php

namespace App\Enums;

/**
 * Booking application statuses (Features guide F2). Approved = an active
 * reservation; MovedIn = the reservation became a tenancy.
 */
enum ApplicationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case MovedIn = 'moved_in';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for the owner',
            self::Approved => 'Reserved',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::MovedIn => 'Moved in',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Approved], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Approved->value];
    }
}
