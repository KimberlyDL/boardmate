<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Scheduler\Contracts\DailyJob;
use App\Services\Tenancies\MoveOutService;
use Carbon\CarbonImmutable;

/** C1 (SC-29): units still lived in after the tenancy's end date show Overstaying. A flag only. */
class FlagOverstaysJob implements DailyJob
{
    public function __construct(private readonly MoveOutService $moveOuts) {}

    public function key(): string
    {
        return 'tenancies.flag_overstays';
    }

    public function run(CarbonImmutable $day): string
    {
        return "Flagged {$this->moveOuts->flagOverstays($day)} overstay(s).";
    }
}
