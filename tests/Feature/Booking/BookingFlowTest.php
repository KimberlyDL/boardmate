<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Models\BookingApplication;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Manila'));
    Notification::fake();
    Storage::fake('local');

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 180000]]);
    $this->property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
});

afterEach(fn () => Date::setTestNow());

/** Move the clock to 9:00 AM Manila on $day and run the daily jobs, as the scheduler would. */
function dailyRunOn(string $day): void
{
    Date::setTestNow(CarbonImmutable::parse("{$day} 09:00", 'Asia/Manila'));
    test()->artisan('boardmate:daily')->assertSuccessful();
}

function applyFor(User $boarder, int $propertyId, array $data = []): TestResponse
{
    return test()->actingAs($boarder, 'sanctum')->postJson("/api/v1/listings/{$propertyId}/applications", $data + [
        'planned_move_in_on' => '2026-10-20',
        'contact_phone' => '0917 123 4567',
        'message' => 'Student at UST.',
    ]);
}

it('lets a boarder apply and tells the owner', function () {
    applyFor($this->kim, $this->property->id)->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.unit', null)
        ->assertJsonPath('data.property.address', null);

    Notification::assertSentTo($this->owner, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::BookingReceived);
});

it('reserves the chosen bedspace until planned move-in + 7 days', function () {
    $id = applyFor($this->kim, $this->property->id)->json('data.id');
    $bed = $this->property->units()->first();

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $bed->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.unit.label', $bed->label)
        ->assertJsonPath('data.reserved_until', '2026-10-27');

    expect($bed->fresh()->status->value)->toBe('reserved');
    Notification::assertSentTo($this->kim, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::BookingApproved && $n->data['reserved_until'] === 'Oct 27, 2026');

    // Kim now sees the full address.
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/applications')
        ->assertJsonPath('data.0.property.address', $this->property->fresh()->fullAddress() ?: null)
        ->assertJsonPath('meta.reservation_id', $id);
});

it('counts the expiry from approval day when move-in is already past', function () {
    $id = applyFor($this->kim, $this->property->id, ['planned_move_in_on' => '2026-10-05'])->json('data.id');
    Date::setTestNow(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Manila'));

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $this->property->units()->first()->id])
        ->assertJsonPath('data.reserved_until', '2026-10-17');
});

it('withdraws the boarder\'s other applications when one is approved', function () {
    $other = makeProperty(User::factory()->owner()->create(), ['details' => ['name' => 'Reyes Dorm']]);
    $other->forceFill(['is_published' => true])->save();

    $here = applyFor($this->kim, $this->property->id)->json('data.id');
    $there = applyFor($this->kim, $other->id)->json('data.id');

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/applications/{$here}/approve", ['unit_id' => $this->property->units()->first()->id])->assertOk();

    expect(BookingApplication::find($there)->status->value)->toBe('cancelled');
    Notification::assertSentTo($this->kim, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::BookingAutoCancelled && $n->data['property_name'] === 'Reyes Dorm');

    // …and they cannot apply anywhere else while holding the reservation.
    applyFor($this->kim, $other->id)->assertUnprocessable()->assertJsonPath('code', 'has_reservation');
});

it('declines the other applicants when the last free bed is taken', function () {
    $this->property->units()->first()->forceFill(['status' => 'occupied'])->save();
    $kimId = applyFor($this->kim, $this->property->id)->json('data.id');
    $benId = applyFor($this->ben, $this->property->id)->json('data.id');

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/applications/{$kimId}/approve", ['unit_id' => $this->property->units()->get()[1]->id])->assertOk();

    expect(BookingApplication::find($benId)->status->value)->toBe('declined')
        ->and(BookingApplication::find($benId)->closed_reason)->toBe('The place is now fully booked.');
    $this->getJson("/api/v1/listings/{$this->property->id}")->assertNotFound(); // nothing bookable left
});

it('refuses to reserve a unit that is not available', function () {
    $id = applyFor($this->kim, $this->property->id)->json('data.id');
    $bed = $this->property->units()->first();
    $bed->forceFill(['not_ready' => true])->save();

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $bed->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);
});

it('declines with a reason the boarder sees', function () {
    $id = applyFor($this->kim, $this->property->id)->json('data.id');

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/applications/{$id}/decline", ['reason' => 'Female boarders only.'])
        ->assertOk()->assertJsonPath('data.status', 'declined');

    Notification::assertSentTo($this->kim, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::BookingDeclined && $n->data['reason'] === 'Female boarders only.');
});

it('frees the bed when the boarder or the owner cancels a reservation', function () {
    $bed = $this->property->units()->first();
    $id = applyFor($this->kim, $this->property->id)->json('data.id');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $bed->id]);

    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/me/applications/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($bed->fresh()->status->value)->toBe('available');
    Notification::assertSentTo($this->owner, BoardMateNotification::class, fn ($n) => $n->event === NotificationEvent::BookingCancelled);

    $id2 = applyFor($this->ben, $this->property->id)->json('data.id');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id2}/approve", ['unit_id' => $bed->id]);
    $this->postJson("/api/v1/applications/{$id2}/cancel", [])->assertUnprocessable(); // reason required
    $this->postJson("/api/v1/applications/{$id2}/cancel", ['reason' => 'Room flooded'])->assertOk();
    expect($bed->fresh()->status->value)->toBe('available');
});

it('expires reservations after their last day and reminds the day before', function () {
    $bed = $this->property->units()->first();
    $id = applyFor($this->kim, $this->property->id)->json('data.id');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $bed->id]);

    dailyRunOn('2026-10-26');
    Notification::assertSentTo($this->kim, BoardMateNotification::class, fn ($n) => $n->event === NotificationEvent::ReservationExpiringSoon);
    expect(BookingApplication::find($id)->status->value)->toBe('approved'); // last day is Oct 27

    dailyRunOn('2026-10-28');
    expect(BookingApplication::find($id)->status->value)->toBe('expired')
        ->and($bed->fresh()->status->value)->toBe('available');
    Notification::assertSentTo($this->owner, BoardMateNotification::class, fn ($n) => $n->event === NotificationEvent::ReservationExpired);
});

it('enforces the booking rules', function () {
    applyFor($this->owner, $this->property->id)->assertUnprocessable()->assertJsonPath('code', 'own_property');

    applyFor($this->kim, $this->property->id)->assertCreated();
    applyFor($this->kim, $this->property->id)->assertUnprocessable()->assertJsonPath('code', 'already_applied');

    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    assignCaretaker($this->property, $manager, 'manager');
    applyFor($manager, $this->property->id)->assertUnprocessable()->assertJsonPath('code', 'caretaker_property');

    applyFor($this->ben, $this->property->id, ['planned_move_in_on' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors(['planned_move_in_on']);

    $hidden = makeProperty($this->owner); // unpublished
    applyFor($this->ben, $hidden->id)->assertNotFound();
});

it('caps a boarder at 5 pending applications', function () {
    foreach (range(1, 5) as $i) {
        $p = makeProperty(User::factory()->owner()->create());
        $p->forceFill(['is_published' => true])->save();
        applyFor($this->ben, $p->id)->assertCreated();
    }
    applyFor($this->ben, $this->property->id)->assertUnprocessable()->assertJsonPath('code', 'too_many_pending');
});

it('lets owners and Managers review, but not Collectors or other owners', function () {
    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $manager, 'manager');
    assignCaretaker($this->property, $collector, 'collector');
    $other = User::factory()->owner()->create();
    $id = applyFor($this->kim, $this->property->id)->json('data.id');

    Notification::assertSentTo($manager, BoardMateNotification::class, fn ($n) => $n->event === NotificationEvent::BookingReceived);

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/applications')->assertJsonCount(1, 'data')->assertJsonPath('meta.pending_count', 1);
    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/applications')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.applicant.phone', '0917 123 4567');
    $this->actingAs($collector, 'sanctum')->getJson('/api/v1/applications')->assertJsonCount(0, 'data');
    $this->actingAs($other, 'sanctum')->getJson('/api/v1/applications')->assertJsonCount(0, 'data');

    $this->actingAs($collector, 'sanctum')->getJson("/api/v1/applications/{$id}")->assertForbidden();
    $this->actingAs($other, 'sanctum')->postJson("/api/v1/applications/{$id}/decline")->assertNotFound();
    $this->actingAs($manager, 'sanctum')->getJson("/api/v1/applications/{$id}")->assertOk()->assertJsonCount(2, 'meta.bookable_units');
});

it('keeps the ID photo private and deletes it 30 days after the application closes', function () {
    $id = applyFor($this->kim, $this->property->id, ['id_document' => UploadedFile::fake()->image('id.jpg')])->json('data.id');
    $path = BookingApplication::find($id)->id_document_path;
    Storage::disk('local')->assertExists($path);

    $url = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/applications/{$id}")->json('data.id_document_url');
    expect($url)->toContain('signature=');

    $this->postJson("/api/v1/applications/{$id}/decline");
    dailyRunOn('2026-10-20');
    Storage::disk('local')->assertExists($path);

    dailyRunOn('2026-11-04');
    Storage::disk('local')->assertMissing($path);
    expect(BookingApplication::find($id)->id_document_purged_at)->not->toBeNull();
});

it('shows "Reserved for" on the owner\'s unit list', function () {
    $bed = $this->property->units()->first();
    $id = applyFor($this->kim, $this->property->id)->json('data.id');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $bed->id]);

    $this->getJson("/api/v1/properties/{$this->property->id}")
        ->assertJsonPath('data.units.0.status', 'reserved')
        ->assertJsonPath('data.units.0.reservation.boarder_name', 'Kim')
        ->assertJsonPath('data.units.0.reservation.reserved_until', '2026-10-27');
});
