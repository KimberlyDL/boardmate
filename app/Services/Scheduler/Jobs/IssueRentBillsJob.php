<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Billing\BillingService;
use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;

/** C4: each tenancy's anniversary bill, issued on its due date (missed days caught up). */
class IssueRentBillsJob implements DailyJob
{
    public function __construct(private readonly BillingService $billing) {}

    public function key(): string
    {
        return 'billing.issue_rent_bills';
    }

    public function run(CarbonImmutable $day): string
    {
        $r = $this->billing->issueDuePeriodicBills($day);

        return "Issued {$r['issued']} bill(s)".($r['skipped'] ? '; skipped: '.implode('; ', $r['skipped']) : '').'.';
    }
}
