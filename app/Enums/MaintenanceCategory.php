<?php

namespace App\Enums;

/** What kind of problem a maintenance request is about (D1). */
enum MaintenanceCategory: string
{
    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Appliance = 'appliance';
    case Furniture = 'furniture';
    case Internet = 'internet';
    case Cleaning = 'cleaning';
    case Pest = 'pest';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Plumbing => 'Plumbing',
            self::Electrical => 'Electrical',
            self::Appliance => 'Appliance',
            self::Furniture => 'Furniture',
            self::Internet => 'Internet',
            self::Cleaning => 'Cleaning',
            self::Pest => 'Pests',
            self::Other => 'Other',
        };
    }
}
