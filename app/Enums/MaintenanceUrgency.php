<?php

namespace App\Enums;

/** How soon a problem needs fixing (D1). Urgent ones top the staff list. */
enum MaintenanceUrgency: string
{
    case Normal = 'normal';
    /** e.g. a leak or no power: the owner and caretakers are told at once. */
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Urgent => 'Urgent',
        };
    }
}
