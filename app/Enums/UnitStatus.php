<?php

namespace App\Enums;

/** Rentable unit status (Billing guide S1). Set by bookings and tenancies, never by hand. */
enum UnitStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Occupied = 'occupied';
    case Leaving = 'leaving';
    case Overstaying = 'overstaying';

    /** Someone holds or lives in the unit; blocks mode switches and deletion. */
    public function isInUse(): bool
    {
        return $this !== self::Available;
    }

    /** @return list<string> */
    public static function inUseValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isInUse()));
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Occupied => 'Occupied',
            self::Leaving => 'Leaving',
            self::Overstaying => 'Overstaying',
        };
    }
}
