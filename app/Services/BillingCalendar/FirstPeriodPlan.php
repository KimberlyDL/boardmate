<?php

namespace App\Services\BillingCalendar;

use App\Enums\PartialPeriodHandling;
use Carbon\CarbonImmutable;

/**
 * A newcomer's first charge under a common due date (SC-21): what it covers,
 * what it costs, and the common due date from which normal periods run.
 */
final readonly class FirstPeriodPlan
{
    public function __construct(
        public PartialPeriodHandling $handling,
        /** First day covered (day after move-in). */
        public CarbonImmutable $startsOn,
        /** Last day covered by the first charge (or the free days, if waived). */
        public CarbonImmutable $endsOn,
        /** Days in the partial period (move-in +1 to the first common due date). */
        public int $partialDays,
        /** What the newcomer pays at move-in for this first period. */
        public int $amountCentavos,
        /** The common due date on which the next (normal) rent is due. */
        public CarbonImmutable $nextDueOn,
    ) {}
}
