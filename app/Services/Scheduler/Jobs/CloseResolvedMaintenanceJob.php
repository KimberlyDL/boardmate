<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Maintenance\MaintenanceService;
use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;

/** D1: a fixed request the boarder never confirmed closes 3 days after it was fixed. */
class CloseResolvedMaintenanceJob implements DailyJob
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function key(): string
    {
        return 'maintenance.close_resolved';
    }

    public function run(CarbonImmutable $day): string
    {
        return "Closed {$this->maintenance->closeResolved($day)} fixed request(s).";
    }
}
