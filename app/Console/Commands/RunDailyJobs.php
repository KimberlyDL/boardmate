<?php

namespace App\Console\Commands;

use App\Services\Scheduler\DailyJobRunner;
use App\Support\ManilaDate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('boardmate:daily {--date= : Run for this Manila date (YYYY-MM-DD) instead of today, e.g. to catch up}')]
#[Description('Run all BoardMate daily jobs once for the day (safe to run twice)')]
class RunDailyJobs extends Command
{
    public function handle(DailyJobRunner $runner): int
    {
        $day = $this->option('date') ? ManilaDate::parse($this->option('date'))->startOfDay() : ManilaDate::today();

        $this->components->info('Daily jobs for '.$day->toDateString());

        $failed = false;
        foreach ($runner->run($day) as $result) {
            $this->components->twoColumnDetail("{$result['job']} <fg=gray>{$result['summary']}</>", strtoupper($result['status']));
            $failed = $failed || $result['status'] === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
