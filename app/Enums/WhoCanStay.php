<?php

namespace App\Enums;

/** Who a room is for (2026-10-09): shown on the room and filterable in the Finder. */
enum WhoCanStay: string
{
    case Any = 'any';
    case Female = 'female';
    case Male = 'male';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Anyone',
            self::Female => 'Female only',
            self::Male => 'Male only',
        };
    }
}
