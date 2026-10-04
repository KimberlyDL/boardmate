<?php

namespace App\Console\Commands;

use App\Services\Scheduler\DailyJobRunner;
use App\Support\ManilaDate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('boardmate:daily
    {--date= : Run for this Manila date (YYYY-MM-DD) instead of today, e.g. to catch up}
    {--simulate : Allow a future --date; jobs run but are not recorded as done, so the real run that day still happens}')]
#[Description('Run all BoardMate daily jobs once for the day (safe to run twice)')]
class RunDailyJobs extends Command
{
    public function handle(DailyJobRunner $runner): int
    {
        $today = ManilaDate::today();
        $day = $this->option('date') ? ManilaDate::parse($this->option('date'))->startOfDay() : $today;
        $simulate = (bool) $this->option('simulate');

        // Recording a future day as done would make the real run that day skip its jobs.
        if ($day->greaterThan($today) && ! $simulate) {
            $this->components->error("{$day->toDateString()} is in the future. Add --simulate to try it without recording the run.");

            return self::FAILURE;
        }

        $this->components->info('Daily jobs for '.$day->toDateString().($simulate ? ' (simulation: not recorded)' : ''));
        if ($simulate) {
            $this->components->warn('Jobs still make their real changes (expiries, notices, purges). Use test data only.');
        }

        $failed = false;
        foreach ($runner->run($day, record: ! $simulate) as $result) {
            $this->components->twoColumnDetail("{$result['job']} <fg=gray>{$result['summary']}</>", strtoupper($result['status']));
            $failed = $failed || $result['status'] === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
