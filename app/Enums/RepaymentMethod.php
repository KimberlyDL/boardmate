<?php

namespace App\Enums;

/** How a member paid another back (D5). */
enum RepaymentMethod: string
{
    case Cash = 'cash';
    /** GCash, Maya or a bank transfer: a reference or proof helps the one paid confirm it. */
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Online => 'Online (GCash, Maya, bank)',
        };
    }
}
