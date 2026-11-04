<?php

namespace App\Services\Finance;

use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The finance screens' data, shared by the API and the PDF statements so both
 * always show the same figures: every property, one property by room, one
 * room by stay, and one stay.
 */
class FinanceOverview
{
    public function __construct(
        private readonly FinanceFigures $figures,
        private readonly StayFinance $stays,
    ) {}

    /**
     * @param  Collection<int, Property>  $properties
     * @return array<string, mixed>
     */
    public function portfolio(Collection $properties, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $properties->pluck('id')->all();
        $grouped = $this->figures->grouped($ids, $from, $to);

        [$prevFrom, $prevTo] = $this->previousPeriod($from, $to);

        return [
            'period' => $this->period($from, $to),
            'totals' => $this->figures->total($grouped),
            'previous' => $this->previous($prevFrom, $prevTo, $this->figures->total($this->figures->grouped($ids, $prevFrom, $prevTo))),
            'by_property' => $properties->map(fn (Property $p) => ['property' => ['id' => $p->id, 'name' => $p->name]] + $grouped[$p->id])->values()->all(),
            'series' => $this->figures->series($ids, $to),
        ];
    }

    /**
     * The property's totals and each room's figures (a `room` of null holds
     * money of rooms since deleted).
     *
     * @return array<string, mixed>
     */
    public function property(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byRoom = $this->figures->grouped([$property->id], $from, $to, FinanceFigures::BY_ROOM);
        $rooms = $property->rooms()->ordered()->get()->map(fn (Room $r) => ['room' => $this->roomRef($r)]
            + ($byRoom[$r->id] ?? $this->figures->none(FinanceFigures::BY_ROOM)));
        if (isset($byRoom[0])) {
            $rooms->push(['room' => null] + $byRoom[0]);
        }

        [$prevFrom, $prevTo] = $this->previousPeriod($from, $to);

        return [
            'property' => ['id' => $property->id, 'name' => $property->name],
            'period' => $this->period($from, $to),
            'totals' => $this->figures->total($this->figures->grouped([$property->id], $from, $to)),
            'previous' => $this->previous($prevFrom, $prevTo, $this->figures->total($this->figures->grouped([$property->id], $prevFrom, $prevTo))),
            'rooms' => $rooms->values()->all(),
            'series' => $this->figures->series([$property->id], $to),
        ];
    }

    /**
     * The room's figures and each stay in it during the period (whole-stay
     * totals: billed and paid since move-in, balance today).
     *
     * @return array<string, mixed>
     */
    public function room(Room $room, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byRoom = $this->figures->grouped([$room->property_id], $from, $to, FinanceFigures::BY_ROOM);
        $stays = $this->stays->inRoom($room->id, $from->toDateString(), $to->toDateString());
        $totals = $this->stays->summaries($stays->pluck('id')->all());
        [$prevFrom, $prevTo] = $this->previousPeriod($from, $to);
        $prevByRoom = $this->figures->grouped([$room->property_id], $prevFrom, $prevTo, FinanceFigures::BY_ROOM);

        return [
            'property' => ['id' => $room->property_id, 'name' => $room->property->name],
            'room' => $this->roomRef($room),
            'period' => $this->period($from, $to),
            'totals' => $byRoom[$room->id] ?? $this->figures->none(FinanceFigures::BY_ROOM),
            'previous' => $this->previous($prevFrom, $prevTo, $prevByRoom[$room->id] ?? $this->figures->none(FinanceFigures::BY_ROOM)),
            'stays' => $stays->map(fn (Tenancy $t) => [
                'stay' => [
                    'id' => $t->id,
                    'status' => $t->status->value,
                    'tenant' => ['id' => $t->tenant_id, 'name' => $t->tenant?->name],
                    'unit' => $t->unit ? ['id' => $t->unit->id, 'label' => $t->unit->label] : null,
                    'moved_in_on' => $t->moved_in_on?->toDateString(),
                    'moved_out_on' => $t->moved_out_on?->toDateString(),
                ],
            ] + $totals[$t->id])->values()->all(),
            'series' => $this->figures->series([$room->property_id], $to, roomId: $room->id),
        ];
    }

    /** @return array<string, mixed> */
    public function stay(Tenancy $tenancy): array
    {
        return $this->stays->detail($tenancy);
    }

    /** @return array{id: int, label: string, floor: ?int} */
    private function roomRef(Room $room): array
    {
        return ['id' => $room->id, 'label' => $room->label(), 'floor' => $room->floor];
    }

    /**
     * The period just before, for "vs last period": as many whole months when
     * the period is whole months (October → September; Jul–Sep → Apr–Jun),
     * otherwise as many days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previousPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $prevTo = $from->subDay();
        if ($from->day === 1 && $to->isSameDay($to->endOfMonth())) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;

            return [$from->subMonthsNoOverflow($months), $prevTo];
        }

        return [$prevTo->subDays((int) $from->diffInDays($to)), $prevTo];
    }

    /**
     * The figures of the period just before that can differ from period to
     * period. Outstanding, credit, deposits held and occupancy are as of today,
     * so they are left out.
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function previous(CarbonImmutable $from, CarbonImmutable $to, array $figures): array
    {
        return [
            'period' => $this->period($from, $to),
            'billed' => $figures['billed']['total'],
            'collected' => $figures['collected']['total'],
            'collection_rate' => $figures['collection']['rate'],
            'deposits_received' => $figures['deposits']['received'],
            'utilities_billed' => $figures['utilities']['billed'],
            'provider_cost' => $figures['utilities']['provider_cost'],
            'repair_costs' => $figures['repair_costs'],
        ];
    }

    /** @return array{from: string, to: string} */
    private function period(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
