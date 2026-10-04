<?php

namespace App\Enums;

/** One bill source attached to a property (D2). */
enum UtilityType: string
{
    case Electricity = 'electricity';
    case Water = 'water';
    case Internet = 'internet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => 'Electricity',
            self::Water => 'Water',
            self::Internet => 'Internet',
            self::Other => 'Other',
        };
    }
}
