<?php

namespace App\Enums;

/**
 * A maintenance request's life (D1): Submitted → In progress → Resolved →
 * Closed (the boarder confirms, or 3 days pass), or Cancelled with a reason.
 */
enum MaintenanceStatus: string
{
    case Submitted = 'submitted';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::InProgress => 'In progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Still needs work (not yet resolved). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::InProgress], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Submitted->value, self::InProgress->value];
    }
}
