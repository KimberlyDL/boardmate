<?php

namespace App\Enums;

/** Kinds of place an owner lists (System Design B1). */
enum PropertyType: string
{
    case Apartment = 'apartment';
    case Dorm = 'dorm';
    case BoardingHouse = 'boarding_house';
    case MiniHouse = 'mini_house';
    case Studio = 'studio';

    public function label(): string
    {
        return match ($this) {
            self::Apartment => 'Apartment',
            self::Dorm => 'Dorm',
            self::BoardingHouse => 'Boarding house',
            self::MiniHouse => 'Mini house',
            self::Studio => 'Studio',
        };
    }
}
