<?php

namespace App\Services\Reports\Operations;

use App\Enums\PropertyAbility;
use App\Models\MaintenanceRequest;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Maintenance requests (D1) reported in the period, open and closed, with how long they took and their cost. */
class RepairsReport extends Report
{
    public function key(): string
    {
        return 'repairs';
    }

    public function label(): string
    {
        return 'Repairs';
    }

    public function description(): string
    {
        return 'Every repair request reported between two dates, open or closed, with its cost.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function headers(): array
    {
        return ['Property', 'Reported', 'Where', 'Category', 'Urgency', 'Problem', 'Reported by', 'Status', 'Assigned to', 'Fixed on', 'Days to fix', 'What was done', 'Repair cost (PHP)', 'Charged to boarder (PHP)'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $requests = MaintenanceRequest::whereIn('property_id', $properties->pluck('id'))
            ->whereBetween('created_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->with(['room', 'reporter:id,name', 'assignee:id,name', 'chargeBill.lines'])
            ->orderBy('property_id')->orderBy('created_at')->orderBy('id');

        foreach ($requests->lazy(200) as $r) {
            $reported = $r->created_at?->setTimezone('Asia/Manila');
            $fixed = $r->resolved_at?->setTimezone('Asia/Manila');
            yield [
                $names[$r->property_id] ?? '',
                $this->day($reported),
                $r->room ? "Room {$r->room->label()}" : 'Shared areas',
                $r->category->label(),
                $r->urgency->label(),
                $r->description,
                $r->reporter?->name,
                $r->status->label(),
                $r->assignee?->name,
                $this->day($fixed),
                $fixed && $reported ? (int) $reported->startOfDay()->diffInDays($fixed->startOfDay()) : '',
                $r->resolution,
                $this->pesos($r->repair_cost_centavos),
                $r->chargeBill ? $this->pesos($r->chargeBill->totalCentavos()) : '',
            ];
        }
    }
}
