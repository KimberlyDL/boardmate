<?php

namespace App\Services\Billing;

use App\Enums\BillKind;
use App\Models\Tenancy;
use App\Services\BillingCalendar\BillingCalendar;
use App\Services\BillingCalendar\RentPeriod;
use App\Services\Pricing\PriceBook;
use Carbon\CarbonImmutable;

/**
 * What a tenant will be billed next, before the bill exists: the next rent
 * period not yet billed, with an estimate at today's rent and agreed rate.
 * Utilities are not estimated (metered ones are only known after the month).
 */
class UpcomingBills
{
    public function __construct(private readonly PriceBook $prices) {}

    /**
     * The next rent not billed yet, or null when no more rent is coming
     * (leaving before the next period starts) or no rent is set.
     *
     * @return array{kind: string, label: string, due_on: string, period_start_on: string, period_end_on: string, estimate_centavos: int|null}|null
     */
    public function nextRent(Tenancy $tenancy, CarbonImmutable $today): ?array
    {
        $period = $this->nextUnbilledPeriod($tenancy, $today);
        if (! $period) {
            return null;
        }

        $rent = $this->prices->amountOn($tenancy->unit, $today);
        $discount = $tenancy->discounts->first(fn ($d) => $d->effective_from->lte($period->startsOn)
            && ($d->effective_to === null || $d->effective_to->gte($period->startsOn)));
        $estimate = $rent === null ? null : $rent - ($discount ? $discount->amountOff($rent) : 0);

        return [
            'kind' => 'rent',
            'label' => 'Rent, '.$period->startsOn->format('M j').' – '.$period->endsOn->format('M j, Y'),
            'due_on' => $period->dueOn->toDateString(),
            'period_start_on' => $period->startsOn->toDateString(),
            'period_end_on' => $period->endsOn->toDateString(),
            'estimate_centavos' => $estimate,
        ];
    }

    /** The first period due today or later that DormLink bills and has not billed yet. */
    private function nextUnbilledPeriod(Tenancy $tenancy, CarbonImmutable $today): ?RentPeriod
    {
        $billed = $tenancy->bills()->where('kind', BillKind::Periodic->value)
            ->pluck('period_start_on')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->flip();

        foreach (BillingCalendar::periods($tenancy->moved_in_on, $tenancy->anchor_day) as $period) {
            if ($tenancy->end_on && $period->startsOn->greaterThan($tenancy->end_on)) {
                return null;
            }
            if ($period->dueOn->lt($today) || ! $tenancy->billsPeriod($period) || $billed->has($period->startsOn->toDateString())) {
                continue;
            }

            return $period;
        }

        return null;
    }
}
