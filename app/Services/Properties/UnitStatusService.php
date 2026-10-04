<?php

namespace App\Services\Properties;

use App\Enums\UnitStatus as S;
use App\Models\RentableUnit;
use LogicException;

/**
 * The ONLY place a rentable unit's status changes (Billing guide S1). Booking
 * uses it now; tenancies (move-in, notice, move-out, overstay) will too, so
 * statuses cannot drift.
 *
 * Every move is a compare-and-set: it only applies if the unit is still in
 * the expected status, so a stale caller cannot overwrite a newer status.
 */
class UnitStatusService
{
    /** @var array<string, list<S>> from => allowed targets */
    public const ALLOWED = [
        'available' => [S::Reserved, S::Occupied],             // reservation, or walk-in move-in
        'reserved' => [S::Available, S::Occupied],             // cancelled/expired, or moved in
        'occupied' => [S::Leaving, S::Available],              // notice given, or left without notice
        'leaving' => [S::Occupied, S::Available, S::Overstaying], // notice withdrawn, moved out, stayed past the end date
        'overstaying' => [S::Available, S::Leaving],           // finally left, or end date extended
    ];

    public static function canMove(S $from, S $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value] ?? [], true);
    }

    /**
     * Move the unit from its current status to $to.
     *
     * @throws LogicException when the move is not allowed or the unit changed meanwhile
     */
    public function move(RentableUnit $unit, S $to, ?S $expectedFrom = null): RentableUnit
    {
        $from = $expectedFrom ?? $unit->status;

        if (! self::canMove($from, $to)) {
            throw new LogicException("A unit cannot go from {$from->value} to {$to->value}.");
        }

        $updated = RentableUnit::whereKey($unit->id)
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'updated_at' => now()]);

        if ($updated === 0) {
            throw new LogicException("{$unit->label} is no longer {$from->label()}.");
        }

        $unit->setRawAttributes(array_merge($unit->getAttributes(), ['status' => $to->value]), true);

        return $unit;
    }

    /** Reserved → Available if (and only if) it is still reserved; no-op otherwise. */
    public function releaseReservation(int $unitId): bool
    {
        return RentableUnit::whereKey($unitId)
            ->where('status', S::Reserved->value)
            ->update(['status' => S::Available->value, 'updated_at' => now()]) > 0;
    }
}
