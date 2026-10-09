<?php

namespace App\Enums;

/** How a room is rented (B1): leased as a whole, or by bedspace. */
enum RentalMode: string
{
    case Whole = 'whole';
    case Bedspaces = 'bedspaces';

    public function label(): string
    {
        return match ($this) {
            self::Whole => 'Whole room',
            self::Bedspaces => 'By bedspace',
        };
    }
}
