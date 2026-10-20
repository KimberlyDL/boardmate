<?php

namespace App\Services\Reports\Operations;

use App\Enums\PropertyAbility;
use App\Models\CurfewViolation;
use App\Models\LateEntryRequest;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Late-entry requests and late arrivals logged (D2) for nights in the period, together by night. */
class CurfewLogReport extends Report
{
    public function key(): string
    {
        return 'curfew';
    }

    public function label(): string
    {
        return 'Late-entry and curfew log';
    }

    public function description(): string
    {
        return 'Late-entry requests and late arrivals logged between two dates, night by night.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function headers(): array
    {
        return ['Property', 'Night', 'Type', 'Tenant', 'Room', 'Time', 'Reason or note', 'Status', 'Decided or logged by'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $ids = $properties->pluck('id');
        $range = [$from->toDateString(), $to->toDateString()];
        $rows = collect();

        LateEntryRequest::whereIn('property_id', $ids)->whereBetween('on_date', $range)->with(['tenancy.tenant:id,name', 'tenancy.room', 'decider:id,name'])->get()
            ->each(fn (LateEntryRequest $r) => $rows->push([$r->property_id, $r->on_date->toDateString(), 1, $r->id, [
                'Late-entry request', $r->tenancy?->tenant?->name, $r->tenancy?->room?->label(), $r->expected_arrival,
                $r->reason.($r->decline_reason ? " (declined: {$r->decline_reason})" : ''), $r->status->label(), $r->decider?->name,
            ]]));
        CurfewViolation::whereIn('property_id', $ids)->whereBetween('on_date', $range)->with(['tenancy.tenant:id,name', 'tenancy.room', 'logger:id,name'])->get()
            ->each(fn (CurfewViolation $v) => $rows->push([$v->property_id, $v->on_date->toDateString(), 2, $v->id, [
                'Late arrival logged', $v->tenancy?->tenant?->name, $v->tenancy?->room?->label(), $v->arrived_at, $v->note, 'Recorded (no fee)', $v->logger?->name,
            ]]));

        foreach ($rows->sortBy(fn ($r) => [$r[0], $r[1], $r[2], $r[3]])->values() as $r) {
            yield [$names[$r[0]] ?? '', $r[1], ...$r[4]];
        }
    }
}
