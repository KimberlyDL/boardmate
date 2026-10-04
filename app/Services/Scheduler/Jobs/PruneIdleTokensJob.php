<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/** Deletes API tokens unused for longer than SANCTUM_IDLE_DAYS. */
class PruneIdleTokensJob implements DailyJob
{
    public function key(): string
    {
        return 'auth.prune_idle_tokens';
    }

    public function run(CarbonImmutable $day): string
    {
        $cutoff = now()->subDays(config('boardmate.token_idle_days'));

        $deleted = PersonalAccessToken::query()
            ->where(fn ($q) => $q->where('last_used_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $cutoff)))
            ->delete();

        return "Deleted {$deleted} expired token(s).";
    }
}
