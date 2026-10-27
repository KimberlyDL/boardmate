<?php

namespace App\Console\Commands;

use App\Support\ManilaDate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * For hosts without cron (Render): an outside service calls
 * POST /api/v1/internal/tick every 5 minutes, which runs this command.
 * Unlike `schedule:run`, it does not need to land on the exact minute:
 * the daily jobs run on the first tick at or after 9:00 AM Manila, and
 * `dormlink:daily` skips jobs that already ran that day.
 */
#[Signature('dormlink:tick')]
#[Description('Run whatever scheduled work is due now (curfew reminders; daily jobs from 9:00 AM Manila)')]
class RunTick extends Command
{
    public const DAILY_FROM_HOUR = 9;

    public function handle(): int
    {
        $lock = Cache::lock('dormlink:tick', 15 * 60);

        if (! $lock->get()) {
            $this->components->warn('A tick is still running; skipped.');

            return self::SUCCESS;
        }

        try {
            $failed = $this->call('dormlink:curfew-reminders') !== self::SUCCESS;

            if (ManilaDate::now()->hour >= self::DAILY_FROM_HOUR) {
                $failed = $this->call('dormlink:daily') !== self::SUCCESS || $failed;
            }
        } finally {
            $lock->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
