<?php

namespace App\Services\Reports;

use App\Enums\PropertyAbility;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One CSV report (A10). It reads the same tables as the rest of the app and
 * yields one row at a time, so a large export streams without holding
 * everything in memory. Money is written in pesos with two decimals, dates
 * as YYYY-MM-DD.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** One sentence for the reports screen. */
    abstract public function description(): string;

    /** Needed on a property to include it. */
    abstract public function ability(): PropertyAbility;

    /** Whether it takes a date range (otherwise it is "as of today" or the whole history). */
    public function usesDates(): bool
    {
        return true;
    }

    /**
     * The dates used when none are sent: this month, unless a report looks ahead.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function defaultPeriod(CarbonImmutable $today): array
    {
        return [$today->startOfMonth(), $today->endOfMonth()->startOfDay()];
    }

    /** @return list<string> */
    abstract public function headers(): array;

    /**
     * @param  Collection<int, Property>  $properties  already checked for ability()
     * @return iterable<list<string|int|null>>
     */
    abstract public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable;

    /** 123456 → "1234.56" (no thousands separator, so spreadsheets read it as a number). */
    protected function pesos(?int $centavos): string
    {
        return $centavos === null ? '' : number_format($centavos / 100, 2, '.', '');
    }

    protected function day(?CarbonInterface $date): string
    {
        return $date?->toDateString() ?? '';
    }
}
