<?php

namespace App\Services\Reports\Money;

use App\Enums\PropertyAbility;
use App\Models\Bill;
use App\Models\UtilityBill;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Utility bills entered (C4) whose coverage ends in the period, one row per
 * room share with what is still owed on it. Corrections show the bill they
 * replaced.
 */
class UtilityHistoryReport extends Report
{
    public function key(): string
    {
        return 'utilities';
    }

    public function label(): string
    {
        return 'Utility bill history';
    }

    public function description(): string
    {
        return 'Utility bills by coverage period, with each room\'s share and what is still owed.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function headers(): array
    {
        return ['Property', 'Utility', 'Coverage from', 'Coverage to', 'Total (PHP)', 'Corrects bill of', 'Room', 'Room share (PHP)', 'Share bill no.', 'Billed to', 'Still owed (PHP)'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $utilityBills = UtilityBill::whereIn('property_id', $properties->pluck('id'))
            ->whereBetween('coverage_to_on', [$from->toDateString(), $to->toDateString()])
            ->with(['account', 'replaces', 'bills' => fn ($q) => $q->withBalance()->with(['room', 'payer:id,name', 'lines'])])
            ->orderBy('property_id')->orderBy('coverage_to_on')->orderBy('id')->get();

        foreach ($utilityBills as $u) {
            $head = [
                $names[$u->property_id] ?? '',
                $u->account?->name,
                $this->day($u->coverage_from_on),
                $this->day($u->coverage_to_on),
                $this->pesos((int) $u->total_centavos),
                $u->replaces ? $this->day($u->replaces->coverage_from_on).' – '.$this->day($u->replaces->coverage_to_on) : '',
            ];
            if ($u->bills->isEmpty()) {
                yield [...$head, '', '', '', '', ''];

                continue;
            }
            foreach ($u->bills->sortBy(fn (Bill $b) => $b->room?->label()) as $b) {
                yield [...$head, $b->room?->label(), $this->pesos($b->totalCentavos()), $b->number, $b->payer?->name, $this->pesos($b->balanceCentavos())];
            }
        }
    }
}
