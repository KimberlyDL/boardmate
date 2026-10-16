<?php

namespace App\Enums;

/** A payment reminder for one bill (C7, levels 1–3). Days count from the due date. */
enum ReminderKind: string
{
    /** Level 1: a few days before the due date (default 3). */
    case DueSoon = 'due_soon';
    /** Level 1: on the due date. */
    case DueToday = 'due_today';
    /** Level 2: on the chosen overdue days within the grace days (default days 1 and 3). */
    case Overdue = 'overdue';
    /** Level 3: the day after the grace days (default 3, so day 4): marked late, with the first notice. */
    case MarkedLate = 'marked_late';
    /** Sent by the owner or a caretaker from the overdue list, at most once a day. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::DueSoon => 'Due soon',
            self::DueToday => 'Due today',
            self::Overdue => 'Overdue',
            self::MarkedLate => 'Marked late',
            self::Manual => 'Reminder from staff',
        };
    }
}
