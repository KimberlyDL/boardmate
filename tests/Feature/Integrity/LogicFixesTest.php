<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\UnitStatus;
use App\Models\BookingApplication;
use App\Models\PriceRule;
use App\Models\PropertyCaretaker;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Notifications\BoardMateNotification;
use App\Services\Pricing\PriceBook;
use App\Services\Properties\UnitStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Phase 6A: fixes found in the review before tenancies are built.
*/

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Manila'));
    Notification::fake();

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 180000]]);
    $this->property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
});

afterEach(fn () => Date::setTestNow());

function pendingApplication(User $boarder, int $propertyId): BookingApplication
{
    $application = new BookingApplication(['planned_move_in_on' => '2026-10-20', 'contact_phone' => '09170000000']);
    $application->property_id = $propertyId;
    $application->boarder_id = $boarder->id;
    $application->save();

    return $application;
}

// 1. Caretaker level ---------------------------------------------------------

it('applies a caretaker level change to every property they help with', function () {
    $second = makeProperty($this->owner, ['details' => ['name' => 'Annex']]);
    $otherOwnersPlace = makeProperty(User::factory()->owner()->create(), ['details' => ['name' => 'Elsewhere']]);
    $maria = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    assignCaretaker($this->property, $maria, 'manager');
    assignCaretaker($second, $maria, 'manager');
    assignCaretaker($otherOwnersPlace, $maria, 'manager'); // another owner's assignment is untouched
    $link = $maria->employerLinks()->first();

    $this->actingAs($maria, 'sanctum')->patchJson("/api/v1/properties/{$this->property->id}", ['name' => 'Renamed'])->assertOk();

    $this->actingAs($this->owner, 'sanctum')
        ->patchJson("/api/v1/owner/caretakers/{$link->id}", ['access_level' => 'collector'])
        ->assertOk()
        ->assertJsonPath('data.levels_differ', false)
        ->assertJsonPath('data.properties.0.access_level', 'collector');

    expect(PropertyCaretaker::where('caretaker_id', $maria->id)->whereIn('property_id', [$this->property->id, $second->id])->pluck('access_level')->map->value->unique()->all())
        ->toBe(['collector'])
        ->and(PropertyCaretaker::where('property_id', $otherOwnersPlace->id)->value('access_level')->value)->toBe('manager');

    // Downgraded for real: no more property edits.
    $this->actingAs($maria->fresh(), 'sanctum')->patchJson("/api/v1/properties/{$this->property->id}", ['name' => 'Again'])->assertForbidden();
});

it('says when per-property levels differ', function () {
    $second = makeProperty($this->owner, ['details' => ['name' => 'Annex']]);
    $maria = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $maria, 'collector');
    assignCaretaker($second, $maria, 'manager');

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/owner/caretakers')
        ->assertJsonPath('data.caretakers.0.levels_differ', true);
});

// 2. Property deletion -------------------------------------------------------

it('declines pending applications when a property is deleted, and tells the boarders', function () {
    $kim = User::factory()->boarder()->create();
    $application = pendingApplication($kim, $this->property->id);

    $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/properties/{$this->property->id}")->assertOk();

    expect($application->fresh()->status->value)->toBe('declined')
        ->and($application->fresh()->closed_reason)->toBe('This place is no longer listed.');
    Notification::assertSentTo($kim, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::BookingDeclined);
});

it('still refuses to delete a property with a reservation, naming the unit', function () {
    $bed = $this->property->units()->first();
    app(UnitStatusService::class)->move($bed, UnitStatus::Reserved);

    $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/properties/{$this->property->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'property_in_use')
        ->assertJsonFragment(['message' => "Someone is booked into or living in this property ({$bed->label}). It can be deleted once all units are free."]);
});

// 3. Suspension ----------------------------------------------------------------

it('cancels a suspended boarder\'s applications and reservation and frees the unit', function () {
    $kim = User::factory()->boarder()->create();
    $other = makeProperty(User::factory()->owner()->create(), ['details' => ['name' => 'Reyes Dorm']]);
    $reserved = pendingApplication($kim, $this->property->id);
    $bed = $this->property->units()->first();
    app(BookingService::class)->approve($reserved, $bed->id, $this->owner);
    $pending = pendingApplication($kim, $other->id); // set up directly (the API would refuse it during a reservation)

    $this->actingAs(User::factory()->admin()->create(), 'sanctum')
        ->postJson("/api/v1/admin/users/{$kim->id}/suspend")
        ->assertOk();

    expect($reserved->fresh()->status->value)->toBe('cancelled')
        ->and($pending->fresh()->status->value)->toBe('cancelled')
        ->and($bed->fresh()->status)->toBe(UnitStatus::Available);
    Notification::assertSentTo($this->owner, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::BookingCancelled);
});

// 4. Unit status moves ---------------------------------------------------------

it('only allows the defined unit status moves', function () {
    $units = app(UnitStatusService::class);
    $bed = $this->property->units()->first();

    $units->move($bed, UnitStatus::Reserved);
    expect($bed->fresh()->status)->toBe(UnitStatus::Reserved);

    expect(fn () => $units->move($bed, UnitStatus::Overstaying))->toThrow(LogicException::class);
    expect(fn () => $units->move($bed, UnitStatus::Leaving))->toThrow(LogicException::class);
});

it('refuses a move from a status the unit is no longer in', function () {
    $units = app(UnitStatusService::class);
    $bed = $this->property->units()->first();
    $stale = $bed->replicate()->forceFill(['id' => $bed->id]);   // a copy loaded earlier
    $stale->exists = true;

    $units->move($bed, UnitStatus::Reserved);

    expect(fn () => $units->move($stale, UnitStatus::Reserved))->toThrow(LogicException::class, 'no longer Available');
    expect($units->releaseReservation($bed->id))->toBeTrue()
        ->and($units->releaseReservation($bed->id))->toBeFalse();
});

// 5. One occupancy check -------------------------------------------------------

it('uses one in-use check for removing a unit, switching mode and deleting', function () {
    $bed = $this->property->units()->first();
    app(UnitStatusService::class)->move($bed, UnitStatus::Reserved);

    expect($bed->fresh()->isInUse())->toBeTrue()
        ->and($this->property->occupancyBlocks()->pluck('id')->all())->toBe([$bed->id])
        ->and($this->property->hasUnitsInUse())->toBeTrue();

    $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/units/{$bed->id}")->assertUnprocessable();
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/rental-mode", ['rental_mode' => 'whole', 'units' => ['capacity' => 2, 'rent_centavos' => 500000]])
        ->assertUnprocessable();
});

// 6. Locked price rules --------------------------------------------------------

it('never deletes or replaces a price rule a bill used', function () {
    $prices = app(PriceBook::class);
    $unit = $this->property->units()->first();

    // Set today, corrected today: normally the first rule is deleted...
    $first = $prices->set($unit, 190000, null, $this->owner);
    $prices->lock($first);

    // ...but a bill used it, so a same-day correction is refused.
    expect(fn () => $prices->set($unit, 195000, null, $this->owner))->toThrow(InvalidArgumentException::class, 'A bill already uses');

    // A change from a later date closes it and keeps it.
    $prices->set($unit, 200000, '2026-11-01', $this->owner);
    $locked = PriceRule::find($first->id);
    expect($locked)->not->toBeNull()
        ->and($locked->amount_centavos)->toBe(190000)
        ->and($locked->effective_to->toDateString())->toBe('2026-10-31')
        ->and($locked->isLocked())->toBeTrue();

    // A locked scheduled price is not replaced either.
    $scheduled = $prices->upcoming($unit);
    $prices->lock($scheduled);
    expect(fn () => $prices->set($unit, 210000, '2026-11-01', $this->owner))->toThrow(InvalidArgumentException::class);
    expect(PriceRule::find($scheduled->id))->not->toBeNull();
});

it('reports a locked price as a validation error on the rent endpoint', function () {
    $prices = app(PriceBook::class);
    $unit = $this->property->units()->first();
    $prices->lock($prices->set($unit, 200000, '2026-11-01', $this->owner));

    $this->actingAs($this->owner, 'sanctum')
        ->putJson("/api/v1/units/{$unit->id}/rent", ['amount_centavos' => 210000, 'effective_from' => '2026-11-01'])
        ->assertUnprocessable();
});

// 7. Daily jobs on a future date ---------------------------------------------

it('refuses a future --date unless simulating, and a simulation records nothing', function () {
    $this->artisan('boardmate:daily', ['--date' => '2026-10-10'])->assertFailed();
    expect(DB::table('scheduler_runs')->count())->toBe(0);

    $this->artisan('boardmate:daily', ['--date' => '2026-10-10', '--simulate' => true])->assertSuccessful();
    expect(DB::table('scheduler_runs')->count())->toBe(0);

    $this->artisan('boardmate:daily')->assertSuccessful();
    expect(DB::table('scheduler_runs')->where('run_on', '2026-10-04')->count())->toBe(count(config('boardmate.daily_jobs')));
});

// 9–10. Database time and the activity log -----------------------------------

it('keeps the database clock in Manila time', function () {
    expect(DB::selectOne('show timezone')->TimeZone)->toBe('Asia/Manila');

    $dbNow = CarbonImmutable::parse(DB::selectOne("select to_char(now(), 'YYYY-MM-DD HH24:MI') as t")->t, 'Asia/Manila');
    Date::setTestNow();
    expect(abs($dbNow->diffInMinutes(CarbonImmutable::now('Asia/Manila'))))->toBeLessThan(2);
});

it('never cleans the activity log automatically', function () {
    expect(config('activitylog.clean_after_days'))->toBeNull();

    $cleaners = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'activitylog:clean'));
    expect($cleaners)->toBeEmpty();
});

// Final review fixes ------------------------------------------------------------

it('refuses to approve a suspended applicant or an application on a deleted property', function () {
    $bed = $this->property->units()->first();
    $kim = User::factory()->boarder()->create();
    $application = pendingApplication($kim, $this->property->id);
    $kim->forceFill(['suspended_at' => now()])->save();

    expect(fn () => app(BookingService::class)->approve($application, $bed->id, $this->owner))
        ->toThrow(ValidationException::class);

    $ben = User::factory()->boarder()->create();
    $other = pendingApplication($ben, $this->property->id);
    $this->property->delete();
    expect(fn () => app(BookingService::class)->approve($other, $bed->id, $this->owner))
        ->toThrow(ValidationException::class);
    expect($bed->fresh()->status)->toBe(UnitStatus::Available);
});

it('declines an owner\'s pending applicants when the owner is suspended', function (string $route) {
    $kim = User::factory()->boarder()->create();
    $application = pendingApplication($kim, $this->property->id);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'sanctum')
        ->postJson(sprintf($route, $this->owner->id), ['reason' => 'Fake listing reports'])
        ->assertOk();

    expect($application->fresh()->status->value)->toBe('declined')
        ->and($application->fresh()->closed_reason)->toBe('This place is not taking bookings right now.');
    Notification::assertSentTo($kim, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::BookingDeclined);
})->with([
    'owner review' => '/api/v1/admin/owners/%d/suspend',
    'account' => '/api/v1/admin/users/%d/suspend',
]);

it('does not notify a caretaker when their level did not change', function () {
    $maria = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $maria, 'collector');
    $link = $maria->employerLinks()->first();

    $this->actingAs($this->owner, 'sanctum')
        ->patchJson("/api/v1/owner/caretakers/{$link->id}", ['access_level' => 'collector'])
        ->assertOk()
        ->assertJsonPath('message', 'That is already their access level.');

    Notification::assertNotSentTo($maria, BoardMateNotification::class);
});
