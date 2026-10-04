<?php

use App\Enums\UserRole;
use App\Models\BookingApplication;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Role;

/*
| Two Managers approve two different applicants for the LAST free bed at the
| same moment, in separate processes. Exactly one may win; the bed is never
| double-booked.
*/

it('never reserves the last bed twice', function () {
    foreach (UserRole::cases() as $role) {
        Role::findOrCreate($role->value, 'web'); // truncation clears the role table
    }

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
