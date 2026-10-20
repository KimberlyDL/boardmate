<?php

namespace App\Enums;

/** What a house rule is about (D3). */
enum HouseRuleCategory: string
{
    case Payments = 'payments';
    case QuietHours = 'quiet_hours';
    case Curfew = 'curfew';
    case Visitors = 'visitors';
    case Cleanliness = 'cleanliness';
    case Safety = 'safety';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Payments => 'Payments',
            self::QuietHours => 'Quiet hours',
            self::Curfew => 'Curfew',
            self::Visitors => 'Visitors',
            self::Cleanliness => 'Cleanliness',
            self::Safety => 'Safety',
            self::Other => 'Other',
        };
    }
}
