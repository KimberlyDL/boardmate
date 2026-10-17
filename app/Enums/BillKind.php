<?php

namespace App\Enums;

/** What a bill is for (C4). */
enum BillKind: string
{
    /** The anniversary bill: rent for the period starting, fixed utilities for the period just finished. */
    case Periodic = 'periodic';
    /** A room's share of a metered utility bill, to its leader, due N days after issue. */
    case Utility = 'utility';
    /** A one-time charge (damage, lost key, extra occupant). */
    case Charge = 'charge';
    /** At move-out: the last period's fixed utilities (billed in arrears, so no later bill carries them). Full period, no proration. */
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Periodic => 'Monthly bill',
            self::Utility => 'Utility bill',
            self::Charge => 'Charge',
            self::Final => 'Final bill',
        };
    }
}
