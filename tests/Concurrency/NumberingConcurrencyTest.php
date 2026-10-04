<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
| Real parallel processes against the test database: four "cashiers" issue 25
| receipt numbers each for the same owner at the same time. The row lock must
| give 100 different numbers with no gaps. (Committed data is needed, so this
| suite truncates tables instead of using a rolled-back transaction.)
*/

it('never hands out the same number to two processes at once', function () {
    $owner = User::factory()->create();

    $script = sprintf(
        '$o = App\Models\User::find(%d); $s = app(App\Services\Numbering\Contracts\NumberingService::class); for ($i = 0; $i < 25; $i++) { echo $s->next($o, App\Enums\DocumentType::Receipt), PHP_EOL; }',
        $owner->id,
    );

    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'boardmate_test', 'MAIL_MAILER' => 'array'];

    $results = Process::pool(function ($pool) use ($script, $env) {
        for ($i = 0; $i < 4; $i++) {
            $pool->path(base_path())->env($env)->timeout(120)
                ->command([PHP_BINARY, 'artisan', 'tinker', '--execute='.$script]);
        }
    })->start()->wait();

    $numbers = [];
    foreach ($results as $result) {
        expect($result->successful())->toBeTrue($result->errorOutput());
        $numbers = [...$numbers, ...preg_split('/\R/', trim($result->output()))];
    }

    $year = now('Asia/Manila')->year;
    $expected = array_map(fn ($n) => sprintf('OR-%d-%06d', $year, $n), range(1, 100));
    sort($numbers);

    expect($numbers)->toBe($expected)
        ->and(DB::table('document_sequences')->where('owner_id', $owner->id)->value('last_number'))->toBe(100);
});
