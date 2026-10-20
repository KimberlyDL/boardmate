<?php

namespace App\Services\Reports\Operations;

use App\Enums\PropertyAbility;
use App\Models\Visitor;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** The visitor log (D4) for visits in the period. Records older than 90 days are already deleted. */
class VisitorsReport extends Report
{
    public function key(): string
    {
        return 'visitors';
    }

    public function label(): string
    {
        return 'Visitor log';
    }

    public function description(): string
    {
        return 'Every visitor between two dates: whom they visited, overnight stays and check-in times (kept 90 days).';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function headers(): array
    {
        return ['Property', 'Day', 'Visitor', 'Visiting', 'Room', 'Overnight nights', 'Walk-in', 'Status', 'Over the limit', 'Checked in', 'Checked out', 'Decided by', 'Decline reason'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $visitors = Visitor::whereIn('property_id', $properties->pluck('id'))
            ->whereBetween('visit_on', [$from->toDateString(), $to->toDateString()])
            ->with(['tenancy.tenant:id,name', 'tenancy.room', 'decider:id,name'])
            ->orderBy('property_id')->orderBy('visit_on')->orderBy('id');

        $time = fn ($t) => $t?->setTimezone('Asia/Manila')->format('Y-m-d H:i') ?? '';
        foreach ($visitors->lazy(200) as $v) {
            yield [
                $names[$v->property_id] ?? '',
                $this->day($v->visit_on),
                $v->name,
                $v->tenancy?->tenant?->name,
                $v->tenancy?->room?->label(),
                $v->overnight ? $v->nights : '',
                $v->is_walk_in ? 'Yes' : 'No',
                $v->status->label(),
                $v->over_limit ? 'Yes' : 'No',
                $time($v->checked_in_at),
                $time($v->checked_out_at),
                $v->decider?->name,
                $v->decline_reason,
            ];
        }
    }
}
