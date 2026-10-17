<?php

namespace App\Enums;

/** An end date asked for or set on a tenancy (C1). */
enum NoticeStatus: string
{
    /** Filed by the boarder or leader; waits for the owner or a caretaker. */
    case Pending = 'pending';
    /** In force: the tenancy's end date. Staff-set end dates start here. */
    case Approved = 'approved';
    case Declined = 'declined';
    /** Taken back by the boarder before a decision, or cancelled by staff. */
    case Withdrawn = 'withdrawn';
    /** An approved end date later moved (extended or brought forward). */
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
            self::Replaced => 'Replaced',
        };
    }
}
