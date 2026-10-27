<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function tickAt(string $manilaTime): void
{
    test()->travelTo(CarbonImmutable::parse($manilaTime, 'Asia/Manila'));
}

it('is off (404) while CRON_SECRET is not set', function () {
    config(['dormlink.cron_secret' => null]);

    $this->postJson('/api/v1/internal/tick', headers: ['X-Cron-Secret' => ''])->assertNotFound();
});

it('refuses a wrong secret', function () {
    config(['dormlink.cron_secret' => 'right-secret']);

    $this->postJson('/api/v1/internal/tick', headers: ['X-Cron-Secret' => 'wrong'])->assertForbidden();
    $this->postJson('/api/v1/internal/tick')->assertForbidden();
});

it('runs the daily jobs on the first tick from 9:00 AM Manila, once a day', function () {
    config(['dormlink.cron_secret' => 'right-secret']);
    $this->withoutDefer();
    $tick = fn () => $this->postJson('/api/v1/internal/tick', headers: ['X-Cron-Secret' => 'right-secret'])->assertStatus(202);

    tickAt('2026-10-05 08:55');
    $tick();
    expect(DB::table('scheduler_runs')->count())->toBe(0);

    // Not on the exact minute (e.g. a slow wake-up): still runs.
    tickAt('2026-10-05 09:03');
    $tick();
    $ran = DB::table('scheduler_runs')->count();
    expect($ran)->toBe(count(config('dormlink.daily_jobs')));

    tickAt('2026-10-05 09:08');
    $tick();
    expect(DB::table('scheduler_runs')->count())->toBe($ran);
});
