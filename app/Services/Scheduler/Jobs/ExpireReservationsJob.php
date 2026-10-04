<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Booking\BookingService;
use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;

/** F2: reservations with no move-in by their last day expire; day-before reminders. */
class ExpireReservationsJob implements DailyJob
{
    public function __construct(private readonly BookingService $bookings) {}

    public function key(): string
    {
        return 'bookings.expire_reservations';
    }

    public function run(CarbonImmutable $day): string
    {
        $r = $this->bookings->expireReservations($day);

        return "Expired {$r['expired']} reservation(s); reminded {$r['reminded']}.";
    }
}
