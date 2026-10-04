<?php

namespace App\Services\Scheduler\Contracts;

use Carbon\CarbonImmutable;

/**
 * A feature's daily task, run by `boardmate:daily` at 09:00 Asia/Manila.
 * Register it in config/boardmate.php ('daily_jobs').
 *
 * Write run() so it computes what is due AS OF $day (e.g. "periods due on or
 * before $day that have no bill yet"), not "what happened since yesterday".
 * Then a missed day or a second run never double-charges or skips anyone.
 */
interface DailyJob
{
    /** Stable identifier stored in scheduler_runs, e.g. "rent.generate_periods". */
    public function key(): string;

    /** Do the work for $day (a Manila date). Return a one-line summary. */
    public function run(CarbonImmutable $day): string;
}
