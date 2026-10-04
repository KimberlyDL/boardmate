<?php

namespace App\Enums;

/** A rentable unit is the whole property or one bedspace (D1). */
enum UnitKind: string
{
    case Whole = 'whole';
    case Bedspace = 'bedspace';

    public function label(): string
    {
        return match ($this) {
            self::Whole => 'Whole property',
            self::Bedspace => 'Bedspace',
        };
    }
}
