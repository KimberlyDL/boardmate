<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create(['name' => 'Ana Santos']);
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create(['name' => 'Carla']);
    $this->property = makeProperty($this->owner);
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->property->settings()->updateOrCreate([], ['curfew_time' => '22:00', 'extra_occupant_fee_enabled' => true, 'extra_occupant_fee_centavos' => 20000]);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->stay = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

/** What $user got for $event (each message once). */
function houseOpsSent(User $user, NotificationEvent $event)
{
    return Notification::sent($user, DormLinkNotification::class)->filter(fn ($n) => $n->event === $event)->unique(fn ($n) => spl_object_id($n))->values();
}

it('tells staff about a late-entry request, and the tenant about the decision', function () {
    $id = $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/tenancies/{$this->stay->id}/late-entries", ['on_date' => '2026-10-20', 'expected_arrival' => '23:30', 'reason' => 'Group project.'])->json('data.id');
    foreach ([$this->owner, $this->collector] as $staff) {
        expect(houseOpsSent($staff, NotificationEvent::LateEntryRequested)->first()?->data)->toMatchArray(['night' => 'Tue, Oct 20', 'arrival' => '11:30 PM', 'tenant_name' => 'Kim']);
    }

    $this->actingAs($this->collector, 'sanctum')->postJson("/api/v1/late-entries/{$id}/decline", ['reason' => 'Exam week.'])->assertOk();
    expect(houseOpsSent($this->kim, NotificationEvent::LateEntryDecided)->first()->data)->toMatchArray(['what' => 'declined', 'reason' => 'Exam week.']);
});

it('asks staff to approve an overnight stay, tells the tenant, and flags going over the limit to both', function () {
    Visitor::factory()->forTenancy($this->stay)->overnight(3)->create(['visit_on' => '2026-10-05']);
    $id = $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/tenancies/{$this->stay->id}/visitors", ['name' => 'Ana Cruz', 'visit_on' => '2026-10-20', 'overnight' => true, 'nights' => 1])->json('data.id');
    expect(houseOpsSent($this->owner, NotificationEvent::OvernightRequested)->first()->data)->toMatchArray(['visitor_name' => 'Ana Cruz', 'over_limit' => true]);
    expect(houseOpsSent($this->kim, NotificationEvent::OvernightOverLimit))->toHaveCount(0); // not counted until approved

    $this->actingAs($this->collector, 'sanctum')->postJson("/api/v1/visitors/{$id}/approve")->assertOk();
    expect(houseOpsSent($this->kim, NotificationEvent::VisitorDecided)->first()->data['what'])->toBe('approved');
    expect(houseOpsSent($this->kim, NotificationEvent::OvernightOverLimit)->first()->data)->toMatchArray(['audience' => 'tenant', 'used' => 4, 'limit' => 3]);
    expect(houseOpsSent($this->owner, NotificationEvent::OvernightOverLimit)->first()->data)->toMatchArray(['audience' => 'owner', 'fee' => '₱200.00']);
    expect(houseOpsSent($this->collector, NotificationEvent::OvernightOverLimit))->toHaveCount(0); // the owner's call
});

it('tells the tenant when a late arrival is recorded', function () {
    $this->actingAs($this->collector, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/curfew-violations", ['tenancy_id' => $this->stay->id, 'on_date' => '2026-10-17', 'arrived_at' => '23:40'])->assertCreated();

    expect(houseOpsSent($this->kim, NotificationEvent::CurfewViolationLogged)->first()->data)->toMatchArray(['night' => 'Sat, Oct 17', 'arrival' => '11:40 PM']);
});
