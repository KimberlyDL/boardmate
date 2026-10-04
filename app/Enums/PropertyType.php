<?php

namespace App\Enums;

/** Kinds of place an owner lists (Billing guide: Terms, Property). */
enum PropertyType: string
{
    case Apartment = 'apartment';
    case Dorm = 'dorm';
    case BoardingHouse = 'boarding_house';
    case MiniHouse = 'mini_house';

    public function label(): string
    {
        return match ($this) {
            self::Apartment => 'Apartment',
            self::Dorm => 'Dorm',
            self::BoardingHouse => 'Boarding house',
            self::MiniHouse => 'Mini house',
        };
    }
}
