<?php

use App\Enums\SplitMethod;
use App\Services\Split\SplitEngine;

/*
| Expected results are the worked examples in the Billing guide (S6, SC-06,
| SC-07, SC-09, SC-12, SC-13, SC-14), in centavos.
*/

beforeEach(fn () => $this->split = new SplitEngine);

it('SC-13: rounds down and gives the leftover centavo to the largest remainder', function () {
    $r = $this->split->equal(100000, ['A', 'B', 'C']);

    expect($r->shares)->toBe(['A' => 33334, 'B' => 33333, 'C' => 33333])
        ->and($r->allocated())->toBe(100000);
});

it('S6 simple example: ₱600 with weights 1, 1, 0.5', function () {
    expect($this->split->weights(60000, ['A' => 1, 'B' => 1, 'C' => '0.5'])->shares)
        ->toBe(['A' => 24000, 'B' => 24000, 'C' => 12000]);
});

it('SC-09: ₱2,400 with weights 30, 30, 10', function () {
    expect($this->split->weights(240000, ['A' => 30, 'B' => 30, 'C' => 10])->shares)
        ->toBe(['A' => 102857, 'B' => 102857, 'C' => 34286]);
});

it('SC-06: a newcomer present 13 of 30 days of a ₱1,080 bill', function () {
    // Coverage Sep 1–30; the original boarder all month, the newcomer from Sep 18.
    $r = $this->split->occupantDays(108000, '2026-09-01', '2026-09-30', [
        'original' => ['2026-08-01', null],
        'newcomer' => ['2026-09-18', null],
    ]);

    expect($r->method)->toBe(SplitMethod::OccupantDays)
        ->and($r->shares)->toBe(['original' => 75349, 'newcomer' => 32651]);
});

it('SC-06: equal, and newcomer starts on the next bill', function () {
    expect($this->split->equal(108000, ['original', 'newcomer'])->shares)
        ->toBe(['original' => 54000, 'newcomer' => 54000]);

    expect($this->split->weights(108000, ['original' => 1, 'newcomer' => 0])->shares)
        ->toBe(['original' => 108000, 'newcomer' => 0]);
});

it('SC-07: equal, landlord absorbs, and redistribute for a 0.5 boarder', function () {
    expect($this->split->equal(60000, ['A', 'B', 'C'])->shares)
        ->toBe(['A' => 20000, 'B' => 20000, 'C' => 20000]);

    $absorb = $this->split->weightedAbsorb(60000, ['A' => 1, 'B' => 1, 'C' => '0.5']);
    expect($absorb->shares)->toBe(['A' => 20000, 'B' => 20000, 'C' => 10000])
        ->and($absorb->unallocated())->toBe(10000); // landlord covers ₱100

    expect($this->split->weights(60000, ['A' => 1, 'B' => 1, 'C' => '0.5'])->shares)
        ->toBe(['A' => 24000, 'B' => 24000, 'C' => 12000]); // redistribute
});

it('SC-12: one flat ₱100, the other two split the remaining ₱500', function () {
    $r = $this->split->fixedPlusRemainder(60000, ['A' => 10000], ['B' => 1, 'C' => 1]);

    expect($r->shares)->toBe(['A' => 10000, 'B' => 25000, 'C' => 25000])
        ->and($r->isBalanced())->toBeTrue();
});

it('SC-12: typed amounts that do not add up show the gap as unallocated', function () {
    $r = $this->split->custom(60000, ['A' => 10000, 'B' => 25000]);

    expect($r->unallocated())->toBe(25000)
        ->and($this->split->custom(60000, ['A' => 40000, 'B' => 30000])->unallocated())->toBe(-10000);
});

it('SC-12: fixed amounts above the total leave the rest at zero and show the overrun', function () {
    $r = $this->split->fixedPlusRemainder(60000, ['A' => 70000], ['B' => 1]);

    expect($r->shares)->toBe(['A' => 70000, 'B' => 0])
        ->and($r->unallocated())->toBe(-10000);
});

it('SC-14: the wrong and corrected totals split into the ₱360 adjustment each', function () {
    $wrong = $this->split->equal(180000, ['A', 'B']);
    $right = $this->split->equal(108000, ['A', 'B']);

    expect($wrong->shares)->toBe(['A' => 90000, 'B' => 90000])
        ->and($right->shares)->toBe(['A' => 54000, 'B' => 54000])
        ->and($wrong->shareOf('A') - $right->shareOf('A'))->toBe(36000);
});

it('counts occupant days inclusively and ignores stays outside the coverage', function () {
    expect(SplitEngine::daysPresent('2026-09-10', '2026-10-09', '2026-09-25', null))->toBe(15)
        ->and(SplitEngine::daysPresent('2026-09-10', '2026-10-09', '2026-08-01', '2026-09-10'))->toBe(1)
        ->and(SplitEngine::daysPresent('2026-09-10', '2026-10-09', '2026-10-10', null))->toBe(0)
        ->and(SplitEngine::daysPresent('2026-09-10', '2026-10-09', '2026-08-01', '2026-09-01'))->toBe(0);
});

it('gives nobody anything when nobody was present', function () {
    $r = $this->split->occupantDays(50000, '2026-09-01', '2026-09-30', ['A' => ['2026-10-05', null]]);

    expect($r->shares)->toBe(['A' => 0])->and($r->unallocated())->toBe(50000);
});

it('splits negative totals (credits) the same way', function () {
    expect($this->split->equal(-100000, ['A', 'B', 'C'])->shares)->toBe(['A' => -33334, 'B' => -33333, 'C' => -33333]);
});

it('breaks remainder ties by list order, so results repeat', function () {
    expect($this->split->equal(200, ['A', 'B', 'C'])->shares)->toBe(['A' => 67, 'B' => 67, 'C' => 66])
        ->and($this->split->equal(200, ['C', 'B', 'A'])->shares)->toBe(['C' => 67, 'B' => 67, 'A' => 66]);
});

it('rejects bad weights and negative amounts', function (callable $call) {
    $call($this->split);
})->throws(InvalidArgumentException::class)->with([
    'negative weight' => [fn ($s) => $s->weights(100, ['A' => -1])],
    'too many decimals' => [fn ($s) => $s->weights(100, ['A' => '0.12345'])],
    'text weight' => [fn ($s) => $s->weights(100, ['A' => 'half'])],
    'negative fixed' => [fn ($s) => $s->fixedPlusRemainder(100, ['A' => -5], ['B' => 1])],
    'fixed and rest' => [fn ($s) => $s->fixedPlusRemainder(100, ['A' => 5], ['A' => 1])],
]);

it('always adds up exactly and never goes negative, for many random bills', function () {
    mt_srand(20261004);

    for ($i = 0; $i < 2000; $i++) {
        $total = mt_rand(1, 50_000_000);
        $weights = [];
        for ($p = 0, $n = mt_rand(1, 12); $p < $n; $p++) {
            $weights["p{$p}"] = mt_rand(0, 3).'.'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        }
        $weights['p0'] = '1'; // at least one non-zero weight

        $r = $this->split->weights($total, $weights);

        expect($r->allocated())->toBe($total);
        expect(min($r->shares))->toBeGreaterThanOrEqual(0);
    }
});
