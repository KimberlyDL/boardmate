<?php

use App\Support\Money;

it('parses peso input into integer centavos', function (string|int $input, int $expected) {
    expect(Money::toCentavos($input))->toBe($expected);
})->with([
    ['600', 60000],
    ['1,333.33', 133333],
    ['₱4,000', 400000],
    ['0.5', 50],
    ['-360', -36000],
    [1800, 180000],
]);

it('rejects amounts with more than two decimals or garbage', function (string $input) {
    Money::toCentavos($input);
})->throws(InvalidArgumentException::class)->with(['333.333', 'abc', '', '1.2.3']);

it('takes a fraction of an amount, rounding half up, in integers', function (int $amount, int $num, int $den, int $expected) {
    expect(Money::fraction($amount, $num, $den))->toBe($expected);
})->with([
    'SC-21 proration' => [400000, 10, 30, 133333],
    'SC-29 daily rate' => [400000, 1, 30, 13333],
    'exact half rounds up' => [5, 1, 2, 3],
    'negative credit' => [-400000, 10, 30, -133333],
    'whole' => [300000, 30, 30, 300000],
]);

it('formats centavos as pesos', function (int $centavos, string $expected) {
    expect(Money::format($centavos))->toBe($expected);
})->with([
    [133333, '₱1,333.33'],
    [5, '₱0.05'],
    [0, '₱0.00'],
    [-36000, '-₱360.00'],
    [1510000, '₱15,100.00'],
]);
