<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Manila'));
    Notification::fake();
    Storage::fake('local');

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();

    $this->property = makeProperty($this->owner, ['rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 450000]]);
    $this->bedProperty = makeProperty($this->owner, ['units' => ['count' => 3, 'rent_centavos' => 200000]]);
    foreach ([$this->property, $this->bedProperty] as $property) {
        $property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
        assignCaretaker($property, $this->manager, 'manager');
        assignCaretaker($property, $this->collector, 'collector');
    }
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
});

afterEach(fn () => Date::setTestNow());

/** Messages of an event sent to a user. */
function sentOf(User $user, NotificationEvent $event)
{
    // The fake records a message once per channel (email, in-app); count each message once.
    return Notification::sent($user, BoardMateNotification::class)
        ->filter(fn ($n) => $n->event === $event)
        ->unique(fn ($n) => spl_object_id($n))
        ->values();
}

it('welcomes a tenant who moves in and says when the rent is due', function () {
    $applicationId = $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/listings/{$this->property->id}/applications", [
        'planned_move_in_on' => '2026-10-27', 'contact_phone' => '0917 123 4567',
    ])->json('data.id');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$applicationId}/approve", ['unit_id' => $this->property->units()->first()->id])->assertOk();

    $this->postJson("/api/v1/applications/{$applicationId}/move-in", [
        'moved_in_on' => '2026-10-25',
        'emergency_contact' => ['name' => 'Marta', 'phone' => '0917 555 0000'],
        'deposit' => ['amount_centavos' => 450000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 450000, 'method' => 'cash'],
        'leader_consent' => true,
    ])->assertCreated();

    $welcome = sentOf($this->kim, NotificationEvent::TenancyStarted);
    expect($welcome)->toHaveCount(1)
        ->and($welcome[0]->data)->toMatchArray([
            'property_name' => $this->property->name,
            'moved_in_on' => 'Oct 25, 2026',
            'anchor_day' => 25,
            'next_due_on' => 'Nov 25, 2026',
            'is_leader' => true,
        ])
        // Becoming leader at move-in is part of the welcome, not a second message.
        ->and(sentOf($this->kim, NotificationEvent::LeaderAppointed))->toHaveCount(0);
});

it('tells a walk-in that their other applications were withdrawn', function () {
    $other = makeProperty(User::factory()->owner()->create(), ['units' => ['count' => 1, 'rent_centavos' => 150000]]);
    $other->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
    $this->actingAs($this->ben, 'sanctum')->postJson("/api/v1/listings/{$other->id}/applications", [
        'planned_move_in_on' => '2026-10-27', 'contact_phone' => '0917 123 4567',
    ])->assertCreated();

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->bedProperty->id}/tenancies", [
        'unit_id' => $this->bedProperty->units()->first()->id, 'email' => $this->ben->email,
        'moved_in_on' => '2026-10-25',
        'emergency_contact' => ['name' => 'Marta', 'phone' => '0917 555 0000'],
        'deposit' => ['amount_centavos' => 200000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 200000, 'method' => 'cash'],
    ])->assertCreated();

    $withdrawn = sentOf($this->ben, NotificationEvent::ApplicationWithdrawnOnMoveIn);
    expect($withdrawn)->toHaveCount(1)
        ->and($withdrawn[0]->data)->toMatchArray(['property_name' => $other->name, 'moved_property' => $this->bedProperty->name])
        ->and(sentOf($this->ben, NotificationEvent::TenancyStarted)->first()->data['is_leader'])->toBeFalse();
});

it('tells the new leader, and the leader who was replaced', function () {
    $kimTenancy = Tenancy::factory()->forUnit($this->bedProperty->units()->get()[0])->create(['tenant_id' => $this->kim->id]);
    Tenancy::factory()->forUnit($this->bedProperty->units()->get()[1])->create(['tenant_id' => $this->ben->id]);
    $room = $this->bedProperty->rooms()->first();
    $url = "/api/v1/rooms/{$room->id}/leader";
    $this->actingAs($this->owner, 'sanctum');

    $this->putJson($url, ['user_id' => $this->kim->id, 'consent' => true])->assertOk();
    expect(sentOf($this->kim, NotificationEvent::LeaderAppointed))->toHaveCount(1)
        ->and(sentOf($this->kim, NotificationEvent::LeaderAppointed)[0]->data)->toMatchArray([
            'room_code' => $room->code(), 'property_name' => $this->bedProperty->name, 'appointed_by' => $this->owner->name,
        ]);

    $this->putJson($url, ['user_id' => $this->ben->id, 'consent' => true])->assertOk();
    expect(sentOf($this->ben, NotificationEvent::LeaderAppointed))->toHaveCount(1)
        ->and(sentOf($this->kim, NotificationEvent::LeaderEnded))->toHaveCount(1)
        ->and(sentOf($this->kim, NotificationEvent::LeaderEnded)[0]->data['new_leader'])->toBe('Ben')
        ->and($kimTenancy->exists)->toBeTrue();
});

it('tells the owner and Managers when the leader changes who stays, but not when staff do', function () {
    $tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
    RoomLeader::factory()->create(['room_id' => $tenancy->room_id, 'user_id' => $this->kim->id]);
    $url = "/api/v1/rooms/{$tenancy->room_id}/occupants";
    $person = [
        'name' => 'Ericka Cruz', 'joined_on' => '2026-10-20',
        'emergency_contact_name' => 'Marta Cruz', 'emergency_contact_phone' => '0917 123 4567',
    ];

    // The owner adds someone: nobody needs telling.
    $this->actingAs($this->owner, 'sanctum')->postJson($url, ['name' => 'Staff Added'] + $person)->assertCreated();
    Notification::assertNothingSent();

    // The leader adds, then marks someone as left: the owner and the Manager hear of it, the Collector does not.
    $id = $this->actingAs($this->kim, 'sanctum')->postJson($url, $person)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/occupants/{$id}", ['left_on' => '2026-10-24'])->assertOk();
    $this->patchJson("/api/v1/occupants/{$id}", ['name' => 'Ericka C.'])->assertOk(); // a rename is not news

    foreach ([$this->owner, $this->manager] as $staff) {
        $told = sentOf($staff, NotificationEvent::OccupantsChanged);
        expect($told)->toHaveCount(2)
            ->and($told[0]->data)->toMatchArray(['leader_name' => 'Kim', 'summary' => 'added Ericka Cruz as someone who stays'])
            ->and($told[1]->data['summary'])->toBe('marked Ericka Cruz as left on Oct 24, 2026');
    }
    expect(sentOf($this->collector, NotificationEvent::OccupantsChanged))->toHaveCount(0)
        ->and(RoomOccupant::count())->toBe(2);
});

it('tells the tenant when the discount is set or ended, and stays quiet when nothing changed', function () {
    $tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
    $url = "/api/v1/tenancies/{$tenancy->id}/discount";
    $this->actingAs($this->owner, 'sanctum');

    $this->putJson($url, ['kind' => 'fixed', 'value' => 50000, 'effective_from' => '2026-11-26'])->assertOk();
    $this->putJson($url, ['kind' => 'fixed', 'value' => 50000, 'effective_from' => '2026-11-26'])->assertOk();
    $told = sentOf($this->kim, NotificationEvent::DiscountChanged);
    expect($told)->toHaveCount(1)
        ->and($told[0]->data)->toMatchArray(['change' => 'set', 'from' => 'Nov 26, 2026', 'description' => '₱500.00 off your rent']);

    $this->deleteJson($url, ['effective_from' => '2026-11-26'])->assertOk();
    $told = sentOf($this->kim, NotificationEvent::DiscountChanged);
    expect($told)->toHaveCount(2)->and($told[1]->data)->toMatchArray(['change' => 'ended', 'from' => 'Nov 26, 2026']);
});

it('mentions the leader role when a reservation is approved', function () {
    $id = $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/listings/{$this->bedProperty->id}/applications", [
        'planned_move_in_on' => '2026-10-27', 'contact_phone' => '0917 123 4567',
    ])->json('data.id');

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", [
        'unit_id' => $this->bedProperty->units()->first()->id, 'leader' => true,
    ])->assertOk();

    expect(sentOf($this->kim, NotificationEvent::BookingApproved)[0]->data['leader'])->toBeTrue();
});
