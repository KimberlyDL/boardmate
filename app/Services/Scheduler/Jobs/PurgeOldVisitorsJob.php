<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\HouseOperations\VisitorService;
use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;

/** D4: visitor records are deleted 90 days after the visit. */
class PurgeOldVisitorsJob implements DailyJob
{
    public function __construct(private readonly VisitorService $visitors) {}

    public function key(): string
    {
        return 'visitors.purge_old';
    }

    public function run(CarbonImmutable $day): string
    {
        return "Deleted {$this->visitors->purge($day)} old visitor record(s).";
    }
}
