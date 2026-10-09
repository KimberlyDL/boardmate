<?php

namespace App\Enums;

/**
 * A tenancy runs month to month from move-in (System Design C1). Only Active
 * exists until notice and move-out are built; Notice given, Ended and Settled
 * join it then. A booking application is the reservation, so there is no
 * Reserved state here.
 */
enum TenancyStatus: string
{
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
        };
    }
}
