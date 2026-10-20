<?php

use App\Enums\NotificationEvent;
use App\Enums\VisitorStatus;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use App\Services\HouseOperations\CurfewService;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/*
| The design doc's D2 and D4 scenario tables. Most are covered where the
| feature is tested (LateEntryTest, CurfewTest, VisitorTest,
| HouseOpsNotificationsTest, HouseRuleAutoLinesTest); these are the three
| not covered there.
*/

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->stay = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

it('D2: a place with no curfew sends no reminders and takes no late-entry requests', function () {
    expect(app(CurfewService::class)->sendReminders(CarbonImmutable::parse('2026-10-18 21:45', 'Asia/Manila')))->toBe(0);
    Notification::assertNothingSent();

    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/tenancies/{$this->stay->id}/late-entries", ['on_date' => '2026-10-18', 'expected_arrival' => '23:00', 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['on_date']);
    $this->getJson('/api/v1/me/tenancies')->assertJsonPath('data.0.curfew.time', null);
});

it('D4: over the limit, the owner\'s notice mentions no fee unless the owner turned it on', function () {
    Visitor::factory()->forTenancy($this->stay)->overnight(3)->create(['visit_on' => '2026-10-05']);
    $this->property->settings()->updateOrCreate([], ['overnight_requires_approval' => false]);

    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/tenancies/{$this->stay->id}/visitors", ['name' => 'Ana', 'visit_on' => '2026-10-20', 'overnight' => true])
        ->assertCreated()->assertJsonPath('message', 'Registered. This goes over the overnight limit, so the owner is told.');
    Notification::assertSentTo($this->owner, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::OvernightOverLimit && $n->data['fee'] === null);
});

it('D4: a visitor who never leaves stays on the staff list as checked in, day after day', function () {
    Visitor::factory()->forTenancy($this->stay)->create(['visit_on' => '2026-10-14', 'status' => VisitorStatus::CheckedIn, 'checked_in_at' => '2026-10-14 15:00:00']);

    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$this->property->id}/visitors")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'checked_in')
        ->assertJsonPath('data.0.can.check_out', true);
    // Treated as an extra occupant: any fee is the owner's own one-time charge (C3), never automatic.
});
