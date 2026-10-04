<?php

namespace App\Services\Scheduler;

use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduler module: runs every registered daily job once per Manila day.
 *
 * - Already succeeded for the day → skipped (safe to run twice).
 * - Failed earlier → retried.
 * - Marked running for over an hour (the process died) → retried.
 * - One job failing never stops the others.
 */
class DailyJobRunner
{
    public const STALE_RUNNING_MINUTES = 60;

    /** @param  list<DailyJob>  $jobs */
    public function __construct(private readonly array $jobs) {}

    /**
     * @return list<array{job: string, status: string, summary: string|null}>
     */
    public function run(CarbonImmutable $day): array
    {
        $results = [];
        foreach ($this->jobs as $job) {
            $results[] = ['job' => $job->key()] + $this->runOne($job, $day);
        }

        return $results;
    }

    /** @return array{status: string, summary: string|null} */
    private function runOne(DailyJob $job, CarbonImmutable $day): array
    {
        if (! $this->claim($job->key(), $day)) {
            return ['status' => 'skipped', 'summary' => 'Already done for this day.'];
        }

        try {
            $summary = $job->run($day);
            $this->finish($job->key(), $day, 'succeeded', $summary, null);

            return ['status' => 'succeeded', 'summary' => $summary];
        } catch (Throwable $e) {
            report($e);
            Log::error('Daily job failed', ['job' => $job->key(), 'day' => $day->toDateString(), 'error' => $e->getMessage()]);
            $this->finish($job->key(), $day, 'failed', null, $e->getMessage());

            return ['status' => 'failed', 'summary' => $e->getMessage()];
        }
    }

    /** Mark the job running for the day, unless it already succeeded or is running now. */
    private function claim(string $key, CarbonImmutable $day): bool
    {
        return DB::transaction(function () use ($key, $day) {
            DB::table('scheduler_runs')->insertOrIgnore([
                'job' => $key,
                'run_on' => $day->toDateString(),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('scheduler_runs')
                ->where(['job' => $key, 'run_on' => $day->toDateString()])
                ->lockForUpdate()
                ->first();

            $runningElsewhere = $row->status === 'running'
                && $row->started_at !== null
                && now()->diffInMinutes($row->started_at, absolute: true) < self::STALE_RUNNING_MINUTES;

            if ($row->status === 'succeeded' || $runningElsewhere) {
                return false;
            }

            DB::table('scheduler_runs')->where('id', $row->id)->update([
                'status' => 'running',
                'attempts' => $row->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
                'error' => null,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    private function finish(string $key, CarbonImmutable $day, string $status, ?string $summary, ?string $error): void
    {
        DB::table('scheduler_runs')
            ->where(['job' => $key, 'run_on' => $day->toDateString()])
            ->update([
                'status' => $status,
                'summary' => $summary,
                'error' => $error,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
