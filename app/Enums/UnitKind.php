<?php

namespace App\Enums;

/** A rentable unit is a whole room or one bedspace (B1). */
enum UnitKind: string
{
    case Whole = 'whole';
    case Bedspace = 'bedspace';

    public function label(): string
    {
        return match ($this) {
            self::Whole => 'Whole room',
            self::Bedspace => 'Bedspace',
        };
    }
}
