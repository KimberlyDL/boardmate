<?php

namespace App\Services\Reports;

use App\Services\Reports\Money\CollectionsReport;
use App\Services\Reports\Money\DepositLedgerReport;
use App\Services\Reports\Money\OutstandingReport;
use App\Services\Reports\Money\PriceHistoryReport;
use App\Services\Reports\Money\UtilityHistoryReport;
use App\Services\Reports\Operations\CurfewLogReport;
use App\Services\Reports\Operations\MoveOutsReport;
use App\Services\Reports\Operations\OccupancyReport;
use App\Services\Reports\Operations\RepairsReport;
use App\Services\Reports\Operations\VisitorsReport;

/** The CSV reports owners and caretakers can download (A10), by key. */
final class ReportRegistry
{
    /** @var list<class-string<Report>> */
    private const REPORTS = [
        CollectionsReport::class,
        OutstandingReport::class,
        DepositLedgerReport::class,
        UtilityHistoryReport::class,
        PriceHistoryReport::class,
        OccupancyReport::class,
        MoveOutsReport::class,
        RepairsReport::class,
        VisitorsReport::class,
        CurfewLogReport::class,
    ];

    /** @return array<string, Report> */
    public function all(): array
    {
        $reports = [];
        foreach (self::REPORTS as $class) {
            $report = app($class);
            $reports[$report->key()] = $report;
        }

        return $reports;
    }

    public function find(string $key): ?Report
    {
        return $this->all()[$key] ?? null;
    }
}
