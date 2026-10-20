<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\LateEntryStatus;
use App\Enums\NotificationEvent;
use App\Enums\VisitorStatus;
use App\Models\CurfewViolation;
use App\Models\LateEntryRequest;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use App\Services\HouseOperations\CurfewService;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create(['name' => 'Carla']);
    $this->property = makeProperty($this->owner);
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->property->settings()->updateOrCreate([], ['curfew_time' => '22:00', 'curfew_reminder_minutes' => 30]);
    [$bed1, $bed2] = $this->property->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $this->kimStay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
    $this->benStay = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-10-01')->create(['tenant_id' => $this->ben->id]);
    $this->base = "/api/v1/properties/{$this->property->id}/curfew-violations";
});

afterEach(fn () => Date::setTestNow());

it('lets any caretaker log a late arrival, not on a night that was approved, and flags 3 in 30 days', function () {
    $this->actingAs($this->collector, 'sanctum');
    $log = fn (string $night, ?int $stay = null) => $this->postJson($this->base, ['tenancy_id' => $stay ?? $this->kimStay->id, 'on_date' => $night, 'arrived_at' => '23:40', 'note' => 'Came in through the side gate.']);

    $log('2026-10-17')->assertCreated()->assertJsonPath('data.tenant.name', 'Kim')->assertJsonPath('data.logged_by', 'Carla')->assertJsonPath('message', 'Logged. No fee is added.');
    $log('2026-10-10')->assertUnprocessable()->assertJsonValidationErrors(['on_date']); // more than 7 days back
    $log('2026-10-19')->assertUnprocessable()->assertJsonValidationErrors(['on_date']);
    $log('2026-10-17', 999999)->assertUnprocessable()->assertJsonValidationErrors(['tenancy_id']);

    LateEntryRequest::factory()->forTenancy($this->kimStay)->create(['on_date' => '2026-10-16', 'status' => LateEntryStatus::Approved]);
    $log('2026-10-16')->assertUnprocessable()->assertJsonPath('errors.on_date.0', 'Coming back late that night was approved, so it is not a violation.');

    $log('2026-10-15')->assertCreated();
    $this->getJson($this->base)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.repeated', []);
    $log('2026-10-14')->assertCreated();
    $this->getJson($this->base)->assertJsonPath("meta.repeated.{$this->kimStay->id}", 3);

    // Only the owner or a Manager removes one logged by mistake.
    $id = CurfewViolation::latest('id')->value('id');
    $this->deleteJson("/api/v1/curfew-violations/{$id}")->assertForbidden();
    $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/v1/curfew-violations/{$id}")->assertOk();
    $this->getJson($this->base)->assertJsonPath('meta.repeated', []);
});

it('shows a tenant their own log, without who logged it', function () {
    CurfewViolation::create(['property_id' => $this->property->id, 'tenancy_id' => $this->kimStay->id, 'on_date' => '2026-10-17', 'arrived_at' => '23:40', 'logged_by' => $this->collector->id]);

    $this->actingAs($this->kim, 'sanctum')->getJson("/api/v1/tenancies/{$this->kimStay->id}/curfew-violations")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.logged_by', null);
    $this->getJson("/api/v1/tenancies/{$this->benStay->id}/curfew-violations")->assertNotFound();
    $this->getJson($this->base)->assertNotFound();
    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/tenancies/{$this->kimStay->id}/curfew-violations")->assertJsonPath('data.0.logged_by', 'Carla');
});

it('reminds tenants once, 30 minutes before curfew, except those who muted it or were approved to be late', function () {
    $this->actingAs($this->ben, 'sanctum')->putJson("/api/v1/tenancies/{$this->benStay->id}/curfew-reminders", ['muted' => true])->assertOk()->assertJsonPath('data.muted', true);
    $this->getJson('/api/v1/me/tenancies')->assertJsonPath('data.0.curfew', ['time' => '22:00', 'reminder_minutes' => 30, 'reminders_muted' => true]);
    $curfew = app(CurfewService::class);

    expect($curfew->sendReminders(CarbonImmutable::parse('2026-10-18 21:25', 'Asia/Manila')))->toBe(0); // too early
    expect($curfew->sendReminders(CarbonImmutable::parse('2026-10-18 21:30', 'Asia/Manila')))->toBe(1)
        ->and($curfew->sendReminders(CarbonImmutable::parse('2026-10-18 21:35', 'Asia/Manila')))->toBe(0); // once a night
    Notification::assertSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::CurfewReminder && $n->data['curfew'] === '10:00 PM');
    Notification::assertNotSentTo($this->ben, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::CurfewReminder);

    // The next night Kim is approved to be late: no reminder for her.
    LateEntryRequest::factory()->forTenancy($this->kimStay)->create(['on_date' => '2026-10-19', 'status' => LateEntryStatus::Approved]);
    expect($curfew->sendReminders(CarbonImmutable::parse('2026-10-19 21:45', 'Asia/Manila')))->toBe(0);
    expect($curfew->sendReminders(CarbonImmutable::parse('2026-10-19 22:00', 'Asia/Manila')))->toBe(0); // curfew has passed
});

it('handles a curfew after midnight as the evening before', function () {
    $this->property->settings()->update(['curfew_time' => '00:15']);
    LateEntryRequest::factory()->forTenancy($this->kimStay)->create(['on_date' => '2026-10-18', 'status' => LateEntryStatus::Approved]);

    // 11:50 PM on the 18th: the 12:15 AM curfew of the 19th, the night of the 18th.
    expect(app(CurfewService::class)->sendReminders(CarbonImmutable::parse('2026-10-18 23:50', 'Asia/Manila')))->toBe(1);
    Notification::assertSentTo($this->ben, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::CurfewReminder && $n->data['curfew'] === '12:15 AM');
    Notification::assertNotSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::CurfewReminder);
});

it('sums up what waits at each property for the home screen', function () {
    LateEntryRequest::factory()->forTenancy($this->kimStay)->create(['on_date' => '2026-10-19']);
    LateEntryRequest::factory()->forTenancy($this->kimStay)->create(['on_date' => '2026-10-17']); // past: not counted
    Visitor::factory()->forTenancy($this->benStay)->overnight(1, VisitorStatus::Pending)->create();
    foreach (['2026-10-15', '2026-10-16', '2026-10-17'] as $night) {
        CurfewViolation::create(['property_id' => $this->property->id, 'tenancy_id' => $this->benStay->id, 'on_date' => $night]);
    }

    $this->actingAs($this->collector, 'sanctum')->getJson('/api/v1/me/house-operations-summary')->assertOk()
        ->assertJsonPath('data', [['property_id' => $this->property->id, 'property_name' => $this->property->name, 'late_entries' => 1, 'overnight' => 1, 'repeated' => 1]]);
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson('/api/v1/me/house-operations-summary')->assertJsonCount(0, 'data');
});
