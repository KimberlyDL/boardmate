<?php

namespace App\Enums;

/** A late-entry request's state (D2). */
enum LateEntryStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Still counts for its date (waiting or approved). */
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
