<?php

namespace App\Enums;

/** Who handles a utility account (SC-01, SC-31). */
enum BilledBy: string
{
    case Owner = 'owner';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Group => 'The boarders (shared among themselves)',
        };
    }
}
