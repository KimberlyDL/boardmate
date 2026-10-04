<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Booking\BookingService;
use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;

/** Minimum personal data: ID files of closed applications are deleted after 30 days. */
class PurgeClosedApplicationIdsJob implements DailyJob
{
    public function __construct(private readonly BookingService $bookings) {}

    public function key(): string
    {
        return 'bookings.purge_closed_application_ids';
    }

    public function run(CarbonImmutable $day): string
    {
        return 'Deleted '.$this->bookings->purgeClosedIdDocuments($day).' ID file(s).';
    }
}
