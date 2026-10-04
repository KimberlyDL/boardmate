<?php

use App\Models\BookingApplication;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Process;

/*
| Two Managers approve two different applicants for the LAST free bed at the
| same moment, in separate processes. Exactly one may win; the bed is never
| double-booked.
*/

it('never reserves the last bed twice', function () {
    $owner = User::factory()->owner()->create();
    $property = makeProperty($owner, ['units' => ['count' => 1, 'rent_centavos' => 180000]]);
    $bed = $property->units()->first();

    $ids = collect(range(1, 2))->map(function () use ($property) {
        $application = new BookingApplication(['planned_move_in_on' => now()->addDays(5)->toDateString(), 'contact_phone' => '09170000000']);
        $application->property_id = $property->id;
        $application->boarder_id = User::factory()->boarder()->create()->id;
        $application->save();

        return $application->id;
    });

    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'boardmate_test', 'MAIL_MAILER' => 'array'];
    $script = fn (int $id) => sprintf(
        'try { app(App\Services\Booking\BookingService::class)->approve(App\Models\BookingApplication::find(%d), %d, App\Models\User::find(%d)); echo "won"; } catch (Illuminate\Validation\ValidationException $e) { echo "lost"; }',
        $id, $bed->id, $owner->id,
    );

    $results = Process::pool(function ($pool) use ($ids, $script, $env) {
        foreach ($ids as $id) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, 'artisan', 'tinker', '--execute='.$script($id)]);
        }
    })->start()->wait();

    $outcomes = collect($results)->map(fn ($r) => trim($r->output()))->sort()->values()->all();

    expect($outcomes)->toBe(['lost', 'won'])
        ->and(BookingApplication::where('status', 'approved')->count())->toBe(1)
        ->and($bed->fresh()->status->value)->toBe('reserved');
});

/*
| An approval and a property deletion at the same moment: either the
| approval wins (deletion refused) or the deletion wins (approval refused).
| Never a reservation on a deleted property. Several rounds to hit the race.
*/
it('never leaves a reservation on a property deleted at the same moment', function () {
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'boardmate_test', 'MAIL_MAILER' => 'array'];
    $owner = User::factory()->owner()->create();

    foreach (range(1, 4) as $round) {
        $property = makeProperty($owner, ['units' => ['count' => 1, 'rent_centavos' => 180000]]);
        $bed = $property->units()->first();
        $application = new BookingApplication(['planned_move_in_on' => now()->addDays(5)->toDateString(), 'contact_phone' => '09170000000']);
        $application->property_id = $property->id;
        $application->boarder_id = User::factory()->boarder()->create()->id;
        $application->save();

        $approve = sprintf(
            'try { app(App\Services\Booking\BookingService::class)->approve(App\Models\BookingApplication::find(%d), %d, App\Models\User::find(%d)); echo "approved"; } catch (Illuminate\Validation\ValidationException $e) { echo "refused"; }',
            $application->id, $bed->id, $owner->id,
        );
        $delete = sprintf(
            'echo app(App\Services\Properties\PropertySetup::class)->deleteIfFree(App\Models\Property::find(%d))->isEmpty() ? "deleted" : "kept";',
            $property->id,
        );

        $results = Process::pool(function ($pool) use ($approve, $delete, $env) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, 'artisan', 'tinker', '--execute='.$approve]);
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, 'artisan', 'tinker', '--execute='.$delete]);
        })->start()->wait();

        $outcome = collect($results)->map(fn ($r) => trim($r->output()))->sort()->values()->all();
        $deleted = Property::withTrashed()->find($property->id)->trashed();

        expect($outcome)->toBeIn([['approved', 'kept'], ['deleted', 'refused']])
            ->and($deleted && $bed->fresh()->status->value === 'reserved')->toBeFalse();
    }
});
