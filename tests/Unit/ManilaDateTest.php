<?php

use App\Support\ManilaDate;
use Carbon\CarbonImmutable;

it('treats an end date as lasting until 23:59:59 Manila time', function () {
    $end = ManilaDate::endOfDay('2026-10-20');

    expect($end->format('Y-m-d H:i:s'))->toBe('2026-10-20 23:59:59')
        ->and($end->timezoneName)->toBe('Asia/Manila');
});

it('only counts an end date as passed once the next day starts', function () {
    $lastSecond = CarbonImmutable::parse('2026-10-20 23:59:59', 'Asia/Manila');
    $nextDay = CarbonImmutable::parse('2026-10-21 00:00:00', 'Asia/Manila');

    expect(ManilaDate::hasEnded('2026-10-20', $lastSecond))->toBeFalse()
        ->and(ManilaDate::hasEnded('2026-10-20', $nextDay))->toBeTrue();
});

it('converts other timezones into Manila', function () {
    // 16:30 UTC on Oct 20 is already 00:30 on Oct 21 in Manila (UTC+8).
    $utc = CarbonImmutable::parse('2026-10-20 16:30:00', 'UTC');

    expect(ManilaDate::parse($utc)->toDateString())->toBe('2026-10-21')
        ->and(ManilaDate::hasEnded('2026-10-20', $utc))->toBeTrue();
});
