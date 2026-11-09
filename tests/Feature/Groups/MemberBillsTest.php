<?php

use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/**
 * Nics stays in the whole room Kim rents: the renter listed Nics (no stay of
 * Nics's own, the owner bills Kim). Nics's home and My bills show the room
 * and what Nics has to pay in to the room group.
 */
beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['details' => ['name' => 'De Leon Dormitory'], 'rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 400000]]);
    $this->room = $this->property->rooms()->first();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->nics = User::factory()->boarder()->create(['name' => 'Nics']);
    $tenancy = Tenancy::factory()->forUnit($this->room->units->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
    RoomLeader::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->kim->id]);
    RoomOccupant::factory()->create(['room_id' => $this->room->id, 'tenancy_id' => $tenancy->id, 'user_id' => $this->kim->id, 'name' => 'Kim', 'joined_on' => '2026-10-01']);
    RoomOccupant::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->nics->id, 'name' => 'Nics', 'joined_on' => '2026-10-02']);

    // Kim collects first for the internet: ₱1,000, ₱500 each, due Nov 5.
    $group = $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/groups')->assertOk()->json('data.0.id');
    $this->postJson("/api/v1/groups/{$group}/expenses", ['description' => 'Internet, November', 'amount_centavos' => 100000, 'due_on' => '2026-11-05'])->assertCreated();
    $this->group = $group;
});

afterEach(fn () => Date::setTestNow());

it('shows a listed member the room and what they pay in on their home', function () {
    $home = $this->actingAs($this->nics, 'sanctum')->getJson('/api/v1/me/home')->assertOk()->json('data');

    expect($home['stay'])->toMatchArray([
        'is_member' => true, 'renter_name' => 'Kim', 'room_label' => $this->room->label(),
        'property' => ['id' => $this->property->id, 'name' => 'De Leon Dormitory'],
    ])
        ->and($home['money'])->toBeNull()
        ->and($home['group']['id'])->toBe($this->group)
        ->and($home['group']['owe_centavos'])->toBe(50000)
        ->and($home['group']['next_due'])->toMatchArray(['description' => 'Internet, November', 'due_on' => '2026-11-05', 'my_left_centavos' => 50000]);
});

it('lists a member\'s room shares on My bills, and drops them once left', function () {
    $this->actingAs($this->nics, 'sanctum')->getJson('/api/v1/me/bills')->assertOk()
        ->assertJsonCount(0, 'meta.places')
        ->assertJsonPath('meta.room_shares.0.group_id', $this->group)
        ->assertJsonPath('meta.room_shares.0.owe_centavos', 50000)
        ->assertJsonPath('meta.room_shares.0.items.0', [
            'line_id' => $this->getJson("/api/v1/groups/{$this->group}/expenses")->json('data.0.id'),
            'description' => 'Internet, November', 'kind' => 'collection', 'due_on' => '2026-11-05',
            'my_left_centavos' => 50000, 'is_overdue' => false, 'owed_to' => 'Kim',
        ]);

    // Kim, the collector, has nothing to pay in.
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/bills')->assertJsonPath('meta.room_shares.0.items', []);

    RoomOccupant::where('user_id', $this->nics->id)->update(['left_on' => '2026-10-19']);
    $this->actingAs($this->nics, 'sanctum')->getJson('/api/v1/me/home')->assertJsonPath('data.stay', null);
});
