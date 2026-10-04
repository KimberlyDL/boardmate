<?php

namespace App\Services\BillingCalendar;

use App\Enums\PartialPeriodHandling;
use App\Enums\UtilityDueRule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;

/**
 * Billing calendar module (Billing guide S5): rent periods and due dates.
 * Pure date logic in Asia/Manila; callers pass the tenancy's values.
 *
 * Rules
 * - The anchor day is the move-in day of the month and never changes (D5).
 * - Anchor days 29–31: in a shorter month the due date is that month's last
 *   day, and the anchor comes back in longer months (SC-19).
 * - Rent is paid in advance: a period is due on its due date and covers the
 *   day after through the next due date (D4: move in on the 25th, day 1 is
 *   the 26th, rent due every 25th). The first rent is due at move-in.
 */
final class BillingCalendar
{
    public const TIMEZONE = 'Asia/Manila';

    /** Proration and daily rates use a 30-day month (SC-21, SC-29). */
    public const DAYS_PER_MONTH = 30;

    public static function anchorDay(DateTimeInterface|string $moveIn): int
    {
        return self::date($moveIn)->day;
    }

    /** The due date in a given month for an anchor day (clamped to month end). */
    public static function dueDateIn(int $year, int $month, int $anchorDay): CarbonImmutable
    {
        self::assertAnchor($anchorDay);
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TIMEZONE);

        return $first->setDay(min($anchorDay, $first->daysInMonth));
    }

    /**
     * Rent periods from move-in, numbered from 1. Pass $until to stop after the
     * period containing that day; otherwise iterate as far as needed.
     *
     * @return Generator<int, RentPeriod>
     */
    public static function periods(DateTimeInterface|string $moveIn, ?int $anchorDay = null, DateTimeInterface|string|null $until = null): Generator
    {
        $moveIn = self::date($moveIn);
        $anchorDay ??= $moveIn->day;
        $until = $until === null ? null : self::date($until);

        $due = $moveIn;
        $cursor = $moveIn->startOfMonth();
        for ($n = 1; ; $n++) {
            $cursor = $cursor->addMonthNoOverflow();
            $nextDue = self::dueDateIn($cursor->year, $cursor->month, $anchorDay);

            $period = new RentPeriod($n, $due, $due->addDay(), $nextDue);
            yield $period;

            if ($until !== null && $period->endsOn->greaterThanOrEqualTo($until)) {
                return;
            }
            $due = $nextDue;
        }
    }

    /** The period that covers a given day (null before the first period starts). */
    public static function periodContaining(DateTimeInterface|string $moveIn, DateTimeInterface|string $day, ?int $anchorDay = null): ?RentPeriod
    {
        $day = self::date($day);
        foreach (self::periods($moveIn, $anchorDay, $day) as $period) {
            if ($period->contains($day)) {
                return $period;
            }
        }

        return null;
    }

    /** First rent due date on or after a day (the move-in date counts). */
    public static function nextDueOnOrAfter(DateTimeInterface|string $moveIn, DateTimeInterface|string $day, ?int $anchorDay = null): CarbonImmutable
    {
        $day = self::date($day);
        foreach (self::periods($moveIn, $anchorDay) as $period) {
            if ($period->dueOn->greaterThanOrEqualTo($day)) {
                return $period->dueOn;
            }
        }
    }

    /** First rent due date strictly after a day. */
    public static function nextDueAfter(DateTimeInterface|string $moveIn, DateTimeInterface|string $day, ?int $anchorDay = null): CarbonImmutable
    {
        return self::nextDueOnOrAfter($moveIn, self::date($day)->addDay(), $anchorDay);
    }

    /**
     * SC-04: a price change applies from the first period that STARTS on or
     * after its effective date.
     */
    public static function firstPeriodStartingOnOrAfter(DateTimeInterface|string $moveIn, DateTimeInterface|string $day, ?int $anchorDay = null): RentPeriod
    {
        $day = self::date($day);
        foreach (self::periods($moveIn, $anchorDay) as $period) {
            if ($period->startsOn->greaterThanOrEqualTo($day)) {
                return $period;
            }
        }
    }

    /**
     * Periods to bill for a tenancy with an end date (SC-10): no period starts
     * after the end date. The last one may run past it; settlement (S9)
     * handles that.
     *
     * @return list<RentPeriod>
     */
    public static function periodsUntilEnd(DateTimeInterface|string $moveIn, DateTimeInterface|string $endOn, ?int $anchorDay = null): array
    {
        $endOn = self::date($endOn);
        $periods = [];
        foreach (self::periods($moveIn, $anchorDay) as $period) {
            if ($period->startsOn->greaterThan($endOn)) {
                break;
            }
            $periods[] = $period;
        }

        return $periods;
    }

    /**
     * SC-21: a newcomer under a common due date. The partial period runs from
     * the day after move-in to the first common due date after it.
     */
    public static function commonDateFirstPeriod(
        DateTimeInterface|string $moveIn,
        int $commonDay,
        int $monthlyRentCentavos,
        PartialPeriodHandling $handling,
    ): FirstPeriodPlan {
        $moveIn = self::date($moveIn);
        $start = $moveIn->addDay();

        // First common due date on or after the day after move-in.
        $firstDue = self::dueDateIn($start->year, $start->month, $commonDay);
        if ($firstDue->lessThan($start)) {
            $next = $start->startOfMonth()->addMonthNoOverflow();
            $firstDue = self::dueDateIn($next->year, $next->month, $commonDay);
        }
        $partialDays = (int) $start->diffInDays($firstDue) + 1;
        $afterFirstDue = $firstDue->startOfMonth()->addMonthNoOverflow();
        $followingDue = self::dueDateIn($afterFirstDue->year, $afterFirstDue->month, $commonDay);

        return match ($handling) {
            PartialPeriodHandling::Prorated => new FirstPeriodPlan(
                $handling, $start, $firstDue, $partialDays,
                Money::fraction($monthlyRentCentavos, $partialDays, self::DAYS_PER_MONTH),
                $firstDue,
            ),
            PartialPeriodHandling::FullShifted => new FirstPeriodPlan(
                $handling, $start, $followingDue, $partialDays, $monthlyRentCentavos, $followingDue,
            ),
            PartialPeriodHandling::Waived => new FirstPeriodPlan(
                $handling, $start, $firstDue, $partialDays, 0, $firstDue,
            ),
        };
    }

    /**
     * SC-20: when an actual-bill utility charge is due. "With next rent" means
     * the boarder's next rent due date after the bill is entered.
     */
    public static function utilityDueDate(
        DateTimeInterface|string $issuedOn,
        UtilityDueRule $rule,
        int $daysAfterIssue = 7,
        DateTimeInterface|string|null $moveIn = null,
        ?int $anchorDay = null,
    ): CarbonImmutable {
        $issuedOn = self::date($issuedOn);

        if ($rule === UtilityDueRule::DaysAfterIssue) {
            return $issuedOn->addDays($daysAfterIssue);
        }

        if ($moveIn === null) {
            throw new InvalidArgumentException('Bundling with rent needs the tenancy move-in date.');
        }

        return self::nextDueAfter($moveIn, $issuedOn, $anchorDay);
    }

    /** SC-29 default overstay charge: monthly rent ÷ 30 a day. */
    public static function dailyRate(int $monthlyRentCentavos): int
    {
        return Money::fraction($monthlyRentCentavos, 1, self::DAYS_PER_MONTH);
    }

    private static function assertAnchor(int $day): void
    {
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException("Anchor day must be 1–31, got {$day}.");
        }
    }

    private static function date(DateTimeInterface|string $value): CarbonImmutable
    {
        return ($value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE)
            : CarbonImmutable::parse($value, self::TIMEZONE))->startOfDay();
    }
}
