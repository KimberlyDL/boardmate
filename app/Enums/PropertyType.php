<?php

namespace App\Enums;

/** Kinds of place an owner lists (System Design B1). */
enum PropertyType: string
{
    case Dorm = 'dorm';
    case BoardingHouse = 'boarding_house';
    case Apartment = 'apartment';
    case Studio = 'studio';
    case House = 'house';
    case Condo = 'condo';

    public function label(): string
    {
        return match ($this) {
            self::Dorm => 'Dormitory',
            self::BoardingHouse => 'Boarding house',
            self::Apartment => 'Apartment',
            self::Studio => 'Studio',
            self::House => 'House',
            self::Condo => 'Condo unit',
        };
    }
}
