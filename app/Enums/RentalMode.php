<?php

namespace App\Enums;

/** How a property is rented (D1): leased as a whole, or by bedspace. */
enum RentalMode: string
{
    case Whole = 'whole';
    case Bedspaces = 'bedspaces';

    public function label(): string
    {
        return match ($this) {
            self::Whole => 'Whole property',
            self::Bedspaces => 'By bedspace',
        };
    }
}
