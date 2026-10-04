<?php

use App\Enums\PartialPeriodHandling;
use App\Enums\UtilityDueRule;
use App\Services\BillingCalendar\BillingCalendar as Cal;
use App\Services\BillingCalendar\RentPeriod;

/*
| Expected results are the worked examples in the Billing guide (D4, S5,
| SC-04, SC-19, SC-20, SC-21, SC-29).
*/

/** @return list<array{number:int,due_on:string,starts_on:string,ends_on:string}> */
function firstPeriods(string $moveIn, int $count, ?int $anchor = null): array
{
    $out = [];
    foreach (Cal::periods($moveIn, $anchor) as $p) {
        $out[] = $p->toArray();
        if (count($out) === $count) {
            break;
        }
    }

    return $out;
}

it('SC-19: rent due dates follow the move-in date (move in Sep 25, 2026)', function () {
    expect(firstPeriods('2026-09-25', 4))->toBe([
        ['number' => 1, 'due_on' => '2026-09-25', 'starts_on' => '2026-09-26', 'ends_on' => '2026-10-25'],
        ['number' => 2, 'due_on' => '2026-10-25', 'starts_on' => '2026-10-26', 'ends_on' => '2026-11-25'],
        ['number' => 3, 'due_on' => '2026-11-25', 'starts_on' => '2026-11-26', 'ends_on' => '2026-12-25'],
        ['number' => 4, 'due_on' => '2026-12-25', 'starts_on' => '2026-12-26', 'ends_on' => '2027-01-25'],
    ]);
});

it('SC-19: anchor 31 uses the last day of shorter months, then comes back', function () {
    $periods = firstPeriods('2027-01-31', 4);

    expect(array_map(fn ($p) => [$p['due_on'], $p['starts_on'], $p['ends_on']], $periods))->toBe([
        ['2027-01-31', '2027-02-01', '2027-02-28'],
        ['2027-02-28', '2027-03-01', '2027-03-31'],
        ['2027-03-31', '2027-04-01', '2027-04-30'],
        ['2027-04-30', '2027-05-01', '2027-05-31'],
    ]);
});

it('uses February 29 in a leap year', function () {
    expect(firstPeriods('2028-01-31', 2)[1]['due_on'])->toBe('2028-02-29')
        ->and(Cal::dueDateIn(2028, 2, 30)->toDateString())->toBe('2028-02-29');
});

it('keeps anchors 29 and 30 after February instead of drifting to the 28th', function (int $anchor) {
    $dues = array_column(firstPeriods("2027-01-{$anchor}", 3), 'due_on');

    expect($dues)->toBe(["2027-01-{$anchor}", '2027-02-28', "2027-03-{$anchor}"]);
})->with([29, 30]);

it('SC-04: a price change applies from the first period that starts on or after it', function () {
    // Kim, due day 25: the Oct 26 period keeps the old price; Nov 26 gets the new one.
    expect(Cal::firstPeriodStartingOnOrAfter('2026-09-25', '2026-11-01')->startsOn->toDateString())->toBe('2026-11-26');

    // Ben, due day 12: the period billed Oct 12 covers Oct 13–Nov 12 (so Nov 1–11
    // too) at the old price; the period billed Nov 12 has the new price.
    $old = Cal::periodContaining('2026-08-12', '2026-11-05');
    $new = Cal::firstPeriodStartingOnOrAfter('2026-08-12', '2026-11-01');

    expect($old->dueOn->toDateString())->toBe('2026-10-12')
        ->and($old->endsOn->toDateString())->toBe('2026-11-12')
        ->and($new->dueOn->toDateString())->toBe('2026-11-12');
});

it('SC-20: utility due dates, N days after issue or bundled with the next rent', function () {
    expect(Cal::utilityDueDate('2026-10-12', UtilityDueRule::DaysAfterIssue, 7)->toDateString())->toBe('2026-10-19');

    // Kim (due day 25) and Ben (due day 12); the bill is entered Oct 12.
    expect(Cal::utilityDueDate('2026-10-12', UtilityDueRule::WithNextRent, moveIn: '2026-09-25')->toDateString())->toBe('2026-10-25')
        ->and(Cal::utilityDueDate('2026-10-12', UtilityDueRule::WithNextRent, moveIn: '2026-08-12')->toDateString())->toBe('2026-11-12');
});

it('SC-21: common due date on the 5th, rent ₱4,000, move-in Sep 25', function () {
    $prorated = Cal::commonDateFirstPeriod('2026-09-25', 5, 400000, PartialPeriodHandling::Prorated);
    expect($prorated->startsOn->toDateString())->toBe('2026-09-26')
        ->and($prorated->endsOn->toDateString())->toBe('2026-10-05')
        ->and($prorated->partialDays)->toBe(10)
        ->and($prorated->amountCentavos)->toBe(133333)
        ->and($prorated->nextDueOn->toDateString())->toBe('2026-10-05');

    $shifted = Cal::commonDateFirstPeriod('2026-09-25', 5, 400000, PartialPeriodHandling::FullShifted);
    expect($shifted->endsOn->toDateString())->toBe('2026-11-05')
        ->and($shifted->amountCentavos)->toBe(400000)
        ->and($shifted->nextDueOn->toDateString())->toBe('2026-11-05');

    $waived = Cal::commonDateFirstPeriod('2026-09-25', 5, 400000, PartialPeriodHandling::Waived);
    expect($waived->amountCentavos)->toBe(0)
        ->and($waived->endsOn->addDay()->toDateString())->toBe('2026-10-06'); // ₱0 until Oct 6
});

it('SC-21: moving in the day before the common date gives a 1-day partial period', function () {
    $plan = Cal::commonDateFirstPeriod('2026-10-04', 5, 400000, PartialPeriodHandling::Prorated);

    expect($plan->partialDays)->toBe(1)->and($plan->amountCentavos)->toBe(13333);
});

it('SC-29: the default overstay charge is monthly rent ÷ 30', function () {
    expect(Cal::dailyRate(400000))->toBe(13333);
});

it('SC-10: no period starts after the move-out end date', function () {
    $periods = Cal::periodsUntilEnd('2026-09-25', '2026-10-20');

    expect(count($periods))->toBe(1)
        ->and($periods[0]->endsOn->toDateString())->toBe('2026-10-25');

    // Ending on a due date: that day's period still starts after it, so it is not billed.
    expect(count(Cal::periodsUntilEnd('2026-09-25', '2026-10-25')))->toBe(1)
        ->and(count(Cal::periodsUntilEnd('2026-09-25', '2026-10-26')))->toBe(2);
});

it('finds the next due date, counting the move-in day itself', function () {
    expect(Cal::nextDueOnOrAfter('2026-09-25', '2026-09-25')->toDateString())->toBe('2026-09-25')
        ->and(Cal::nextDueOnOrAfter('2026-09-25', '2026-10-01')->toDateString())->toBe('2026-10-25')
        ->and(Cal::nextDueAfter('2026-09-25', '2026-10-25')->toDateString())->toBe('2026-11-25');
});

it('has no gaps or overlaps for every anchor day across three years, incl. a leap year', function () {
    for ($anchor = 1; $anchor <= 31; $anchor++) {
        $moveIn = Cal::dueDateIn(2027, 1, $anchor); // Jan has 31 days, so this is the real day
        $previous = null;

        /** @var RentPeriod $p */
        foreach (Cal::periods($moveIn, $anchor, '2029-12-31') as $p) {
            expect($p->startsOn->equalTo($p->dueOn->addDay()))->toBeTrue();
            expect($p->endsOn->day)->toBe(min($anchor, $p->endsOn->daysInMonth), "anchor {$anchor} at {$p->endsOn->toDateString()}");
            if ($previous) {
                expect($p->dueOn->equalTo($previous->endsOn))->toBeTrue()
                    ->and($p->startsOn->equalTo($previous->endsOn->addDay()))->toBeTrue();
            }
            $previous = $p;
        }
    }
});

it('rejects impossible anchor days', function () {
    Cal::dueDateIn(2026, 1, 32);
})->throws(InvalidArgumentException::class);
