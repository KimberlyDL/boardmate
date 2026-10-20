<?php

namespace App\Services\Reports\Operations;

use App\Enums\PropertyAbility;
use App\Enums\UnitStatus;
use App\Models\RentableUnit;
use App\Services\Pricing\PriceBook;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Every place today, by property, floor and room: its state, who stays and the rent in force. */
class OccupancyReport extends Report
{
    public function __construct(private readonly PriceBook $prices) {}

    public function key(): string
    {
        return 'occupancy';
    }

    public function label(): string
    {
        return 'Occupancy';
    }

    public function description(): string
    {
        return 'Every place by floor and room as of today: taken, reserved, free or not ready, and who stays.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function headers(): array
    {
        return ['Property', 'Floor', 'Room', 'Place', 'Kind', 'State', 'Tenant', 'Moved in', 'Last day', 'Rent now (PHP)'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        // Places: bedspaces and whole rooms; a bedspace room's whole-room option is not an extra place.
        $units = RentableUnit::whereIn('rentable_units.property_id', $properties->pluck('id'))->where('is_whole_option', false)
            ->join('rooms', 'rooms.id', '=', 'rentable_units.room_id')
            ->orderBy('rentable_units.property_id')->orderByRaw('rooms.floor asc nulls first')->orderBy('rooms.number')->orderBy('rentable_units.sort_order')->orderBy('rentable_units.id')
            ->select('rentable_units.*')
            ->with(['room', 'currentTenancy.tenant:id,name', 'activeReservation.boarder:id,name'])->get();
        $this->prices->preload($units);

        foreach ($units as $u) {
            $stay = $u->currentTenancy;
            yield [
                $names[$u->property_id] ?? '',
                $u->room?->floor,
                $u->room?->label(),
                $u->label,
                $u->kind->label(),
                $u->not_ready && $u->status === UnitStatus::Available ? 'Not ready' : $u->status->label(),
                $stay?->tenant?->name ?? ($u->activeReservation ? "{$u->activeReservation->boarder?->name} (reserved)" : ''),
                $this->day($stay?->moved_in_on),
                $this->day($stay?->end_on),
                $this->pesos($this->prices->amountOn($u)),
            ];
        }
    }
}
