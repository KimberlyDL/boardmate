<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();

    // A bedspace room with two bedspacers, and a second room rented whole with one tenant.
    $this->property = makeProperty($this->owner);
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->bedRoom = $this->property->rooms()->first();
    [$bed1, $bed2] = $this->bedRoom->units;
    $this->kim = User::factory()->boarder()->create();
    $this->ben = User::factory()->boarder()->create();
    Tenancy::factory()->forUnit($bed1)->create(['tenant_id' => $this->kim->id]);
    Tenancy::factory()->forUnit($bed2)->create(['tenant_id' => $this->ben->id]);

    $this->wholeProperty = makeProperty($this->owner, ['rental_mode' => 'whole']);
    $this->wholeRoom = $this->wholeProperty->rooms()->first();
    $this->ericka = User::factory()->boarder()->create();
    Tenancy::factory()->forUnit($this->wholeRoom->units->first())->create(['tenant_id' => $this->ericka->id]);

    $this->url = fn ($room) => "/api/v1/rooms/{$room->id}/leader";
});

it('appoints a leader who lives in the room and records the consent', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => true])
        ->assertOk()
        ->assertJsonPath('data.user.id', $this->kim->id);

    $leader = RoomLeader::current()->where('room_id', $this->bedRoom->id)->sole();
    expect($leader->consent_approved_by)->toBe($this->owner->id)
        ->and($leader->consent_recorded_at)->not->toBeNull();

    $this->getJson('/api/v1/owner/audit-log?event=room.leader_appointed')->assertJsonPath('data.0.event', 'room.leader_appointed');
});

it('lets a Manager appoint but not a Collector', function () {
    $this->actingAs($this->collector, 'sanctum')
        ->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => true])->assertForbidden();

    $this->actingAs($this->manager, 'sanctum')
        ->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => true])->assertOk();
});

it('needs the person\'s consent and a place in the room', function () {
    $this->actingAs($this->owner, 'sanctum');
    $stranger = User::factory()->boarder()->create();

    $this->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['consent']);
    $this->putJson(($this->url)($this->bedRoom), ['user_id' => $stranger->id, 'consent' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
    // Ericka lives in the other room.
    $this->putJson(($this->url)($this->bedRoom), ['user_id' => $this->ericka->id, 'consent' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
    expect(RoomLeader::count())->toBe(0);
});

it('replaces a bedspace room\'s leader and keeps the history', function () {
    $this->actingAs($this->owner, 'sanctum');
    $this->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => true])->assertOk();

    $this->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']); // already the leader

    $this->putJson(($this->url)($this->bedRoom), ['user_id' => $this->ben->id, 'consent' => true, 'reason' => 'Kim asked to step down'])->assertOk();

    expect($this->bedRoom->leader->user_id)->toBe($this->ben->id)
        ->and($this->bedRoom->leaders)->toHaveCount(2)
        ->and($this->bedRoom->leaders->last()->ended_reason)->toBe('Kim asked to step down');
    $this->getJson('/api/v1/owner/audit-log?event=room.leader_replaced')->assertJsonPath('data.0.event', 'room.leader_replaced');
});

it('makes the person who rents a whole room its leader, and nobody else', function () {
    $this->actingAs($this->owner, 'sanctum');
    $other = User::factory()->boarder()->create();

    // Only the tenant lives in the room (one tenancy per unit).
    $this->putJson(($this->url)($this->wholeRoom), ['user_id' => $other->id, 'consent' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
    $this->putJson(($this->url)($this->wholeRoom), ['user_id' => $this->ericka->id, 'consent' => true])->assertOk();
    $this->putJson(($this->url)($this->wholeRoom), ['user_id' => $this->ericka->id, 'consent' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);

    expect($this->wholeRoom->leader->user_id)->toBe($this->ericka->id);
});

it('shows the leader to staff and to the leader, and hides the room from everyone else', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => true])->assertOk();

    foreach ([$this->owner, $this->collector, $this->kim] as $viewer) {
        $this->actingAs($viewer, 'sanctum')->getJson(($this->url)($this->bedRoom))
            ->assertOk()->assertJsonPath('data.user.id', $this->kim->id);
    }

    // Another member, another owner, and a caretaker of another owner cannot see it.
    $otherOwner = User::factory()->owner()->create();
    foreach ([$this->ben, $otherOwner] as $outsider) {
        $this->actingAs($outsider, 'sanctum')->getJson(($this->url)($this->bedRoom))->assertNotFound();
        $this->actingAs($outsider, 'sanctum')
            ->putJson(($this->url)($this->bedRoom), ['user_id' => $this->ben->id, 'consent' => true])->assertNotFound();
    }
    // The leader can see the room but not appoint.
    $this->actingAs($this->kim, 'sanctum')
        ->putJson(($this->url)($this->bedRoom), ['user_id' => $this->ben->id, 'consent' => true])->assertNotFound();
});

it('refuses a suspended account as leader', function () {
    $suspended = User::factory()->boarder()->suspended()->create();
    Tenancy::factory()->forUnit($this->bedRoom->units[2])->create(['tenant_id' => $suspended->id]);

    $this->actingAs($this->owner, 'sanctum')
        ->putJson(($this->url)($this->bedRoom), ['user_id' => $suspended->id, 'consent' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
});

it('shows the leader and head count on the property\'s rooms', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson(($this->url)($this->bedRoom), ['user_id' => $this->kim->id, 'consent' => true])->assertOk();

    $rooms = $this->getJson("/api/v1/properties/{$this->property->id}/rooms")->assertOk()->json('data');

    expect($rooms[0]['leader']['user']['name'])->toBe($this->kim->name);

    // The property's own page (the app's Rooms tab) carries the same.
    $detail = $this->getJson("/api/v1/properties/{$this->property->id}")->assertOk()->json('data.rooms.0');
    expect($detail['leader']['user']['name'])->toBe($this->kim->name)
        ->and($detail['occupants_count'])->toBe(0);

    // A property with no leader yet says so.
    $this->getJson("/api/v1/properties/{$this->wholeProperty->id}")->assertOk()->assertJsonPath('data.rooms.0.leader', null);
});
