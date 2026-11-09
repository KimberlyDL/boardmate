<?php

namespace App\Services\Reports\Money;

use App\Enums\PropertyAbility;
use App\Services\Finance\FinanceFigures;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Each property's money month by month: the finance screen's figures for each month of the dates picked. */
class IncomeByMonthReport extends Report
{
    public function __construct(private readonly FinanceFigures $figures) {}

    public function key(): string
    {
        return 'income_by_month';
    }

    public function label(): string
    {
        return 'Income by month';
    }

    public function description(): string
    {
        return 'Billed, collected, deposits and costs for each month, per property: the same figures as the finance screen.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function hasTotals(): bool
    {
        return true;
    }

    /** The 12 months up to this one. */
    public function defaultPeriod(CarbonImmutable $today): array
    {
        return [$today->startOfMonth()->subMonths(11), $today->endOfMonth()->startOfDay()];
    }

    public function headers(): array
    {
        return ['Property', 'Month', 'Rent (PHP)', 'Fixed utilities (PHP)', 'Utility shares (PHP)', 'Charges (PHP)', 'Corrections (PHP)', 'Billed (PHP)',
            'Payments (PHP)', 'First rent (PHP)', 'Collected (PHP)', 'Paid from deposit (PHP)', 'Deposits received (PHP)', 'Deposits refunded (PHP)',
            'Deposits kept (PHP)', 'Provider utility bills (PHP)', 'Repair costs (PHP)'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $ids = $properties->pluck('id')->all();

        // One month at a time, cut to the dates picked at both ends.
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $start = $month->max($from);
            $end = $month->endOfMonth()->startOfDay()->min($to);
            foreach ($this->figures->grouped($ids, $start, $end) as $id => $f) {
                yield [
                    $names[$id] ?? '',
                    $month->format('Y-m'),
                    $this->pesos($f['billed']['rent']),
                    $this->pesos($f['billed']['fixed_utilities']),
                    $this->pesos($f['billed']['utility_shares']),
                    $this->pesos($f['billed']['charges']),
                    $this->pesos($f['billed']['corrections']),
                    $this->pesos($f['billed']['total']),
                    $this->pesos($f['collected']['payments']),
                    $this->pesos($f['collected']['first_rent']),
                    $this->pesos($f['collected']['total']),
                    $this->pesos($f['paid_from_deposit']),
                    $this->pesos($f['deposits']['received']),
                    $this->pesos($f['deposits']['refunded']),
                    $this->pesos($f['deposits']['kept']),
                    $this->pesos($f['utilities']['provider_cost']),
                    $this->pesos($f['repair_costs']),
                ];
            }
        }
    }
}
