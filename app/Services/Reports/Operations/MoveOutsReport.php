<?php

namespace App\Services\Reports\Operations;

use App\Enums\ApplicationStatus;
use App\Enums\NoticeStatus;
use App\Enums\PropertyAbility;
use App\Enums\TenancyStatus;
use App\Models\BookingApplication;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What changes soon (C1, F2): stays ending in the period (last day set, or
 * asked for and waiting), and reservations that run out in it. By default
 * the next 30 days.
 */
class MoveOutsReport extends Report
{
    public function key(): string
    {
        return 'move_outs';
    }

    public function label(): string
    {
        return 'Upcoming move-outs and reservations';
    }

    public function description(): string
    {
        return 'Stays ending and reservations running out between two dates (by default the next 30 days).';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function defaultPeriod(CarbonImmutable $today): array
    {
        return [$today, $today->addDays(30)];
    }

    public function headers(): array
    {
        return ['Property', 'Date', 'What', 'Who', 'Room', 'Place', 'Status'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $ids = $properties->pluck('id');
        $range = [$from->toDateString(), $to->toDateString()];
        $rows = collect();

        Tenancy::whereIn('property_id', $ids)->where('status', TenancyStatus::NoticeGiven)->whereBetween('end_on', $range)
            ->with(['tenant:id,name', 'room', 'unit'])->get()
            ->each(fn (Tenancy $t) => $rows->push([$t->property_id, $t->end_on, 'Last day of stay', $t->tenant?->name, $t->room?->label(), $t->unit?->label, 'Approved']));

        TenancyNotice::where('status', NoticeStatus::Pending)->whereBetween('end_on', $range)
            ->whereHas('tenancy', fn ($q) => $q->whereIn('property_id', $ids))
            ->with(['tenancy.tenant:id,name', 'tenancy.room', 'tenancy.unit'])->get()
            ->each(fn (TenancyNotice $n) => $rows->push([$n->tenancy->property_id, $n->end_on, 'Last day asked for', $n->tenancy->tenant?->name, $n->tenancy->room?->label(), $n->tenancy->unit?->label, 'Waiting for approval']));

        BookingApplication::whereIn('property_id', $ids)->where('status', ApplicationStatus::Approved)->whereBetween('reserved_until', $range)
            ->with(['boarder:id,name', 'unit.room'])->get()
            ->each(fn (BookingApplication $a) => $rows->push([$a->property_id, $a->reserved_until, 'Reservation runs out', $a->boarder?->name, $a->unit?->room?->label(), $a->unit?->label, 'Not moved in yet']));

        foreach ($rows->sortBy(fn ($r) => [$r[0], $r[1]->toDateString()])->values() as $r) {
            yield [$names[$r[0]] ?? '', $this->day($r[1]), $r[2], $r[3], $r[4], $r[5], $r[6]];
        }
    }
}
