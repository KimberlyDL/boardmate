<?php

namespace App\Services\BillingCalendar;

use Carbon\CarbonImmutable;

/**
 * One rent period. Rent is paid in advance: it is due on `dueOn` and covers
 * `startsOn` (the day after) through `endsOn` (the next due date), both
 * inclusive (D4).
 */
final readonly class RentPeriod
{
    public function __construct(
        public int $number,
        public CarbonImmutable $dueOn,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
    ) {}

    public function days(): int
    {
        return (int) $this->startsOn->diffInDays($this->endsOn) + 1;
    }

    public function contains(CarbonImmutable $day): bool
    {
        return $day->betweenIncluded($this->startsOn, $this->endsOn);
    }

    /** @return array{number: int, due_on: string, starts_on: string, ends_on: string} */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'due_on' => $this->dueOn->toDateString(),
            'starts_on' => $this->startsOn->toDateString(),
            'ends_on' => $this->endsOn->toDateString(),
        ];
    }
}
