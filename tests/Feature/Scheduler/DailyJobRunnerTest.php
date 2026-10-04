<?php

use App\Models\User;
use App\Services\Scheduler\Contracts\DailyJob;
use App\Services\Scheduler\DailyJobRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** A job that counts its runs and can be told to fail. */
function countingJob(string $key, bool &$shouldFail = false): DailyJob
{
    return new class($key, $shouldFail) implements DailyJob
    {
        public int $runs = 0;

        public function __construct(private string $k, private bool &$fail) {}

        public function key(): string
        {
            return $this->k;
        }

        public function run(CarbonImmutable $day): string
        {
            $this->runs++;
            if ($this->fail) {
                throw new RuntimeException('provider down');
            }

            return "ran for {$day->toDateString()}";
        }
    };
}

beforeEach(fn () => $this->day = CarbonImmutable::parse('2026-10-05', 'Asia/Manila'));

it('runs each job once per day, so a second run skips it', function () {
    $job = countingJob('test.counting');
    $runner = new DailyJobRunner([$job]);

    expect($runner->run($this->day)[0]['status'])->toBe('succeeded')
        ->and($runner->run($this->day)[0]['status'])->toBe('skipped')
        ->and($job->runs)->toBe(1);

    $row = DB::table('scheduler_runs')->first();
    expect($row->status)->toBe('succeeded')->and($row->summary)->toBe('ran for 2026-10-05');
});

it('retries a failed job on the next run without blocking the others', function () {
    $fail = true;
    $flaky = countingJob('test.flaky', $fail);
    $steady = countingJob('test.steady');
    $runner = new DailyJobRunner([$flaky, $steady]);

    $first = $runner->run($this->day);
    expect(array_column($first, 'status'))->toBe(['failed', 'succeeded']);

    $fail = false;
    $second = $runner->run($this->day);
    expect(array_column($second, 'status'))->toBe(['succeeded', 'skipped'])
        ->and(DB::table('scheduler_runs')->where('job', 'test.flaky')->value('attempts'))->toBe(2);
});

it('does not start a job that another process is running, but retries a stale one', function () {
    $job = countingJob('test.busy');
    DB::table('scheduler_runs')->insert([
        'job' => 'test.busy', 'run_on' => '2026-10-05', 'status' => 'running',
        'attempts' => 1, 'started_at' => now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $runner = new DailyJobRunner([$job]);

    expect($runner->run($this->day)[0]['status'])->toBe('skipped');

    DB::table('scheduler_runs')->update(['started_at' => now()->subHours(2)]);
    expect($runner->run($this->day)[0]['status'])->toBe('succeeded');
});

it('runs the registered jobs for a chosen day with --date (catch-up)', function () {
    $user = User::factory()->create();
    $user->createToken('stale');
    PersonalAccessToken::query()->update(['last_used_at' => now()->subDays(45)]);

    $this->artisan('boardmate:daily', ['--date' => '2026-10-03'])->assertSuccessful();
    $this->artisan('boardmate:daily', ['--date' => '2026-10-03'])->assertSuccessful();

    expect(PersonalAccessToken::count())->toBe(0)
        ->and(DB::table('scheduler_runs')->where('run_on', '2026-10-03')->value('status'))->toBe('succeeded')
        ->and(DB::table('scheduler_runs')->count())->toBe(count(config('boardmate.daily_jobs')))
        ->and(DB::table('scheduler_runs')->where('status', '!=', 'succeeded')->count())->toBe(0);
});

it('is scheduled at 9:00 AM Manila time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'boardmate:daily'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('Asia/Manila');
});
