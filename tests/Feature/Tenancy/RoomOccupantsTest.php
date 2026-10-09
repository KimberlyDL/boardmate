<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();

    // Kim rents a whole room (capacity 4) and leads it; she is the first occupant.
    $this->property = makeProperty($this->owner, ['rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 400000]]);
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->room = $this->property->rooms()->first();
    $this->kim = User::factory()->boarder()->create();
    $this->tenancy = Tenancy::factory()->forUnit($this->room->units->first())->movedInOn(now('Asia/Manila')->subDays(20)->toDateString())
        ->create(['tenant_id' => $this->kim->id]);
    RoomLeader::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->kim->id]);
    $this->kimRow = RoomOccupant::factory()->create([
        'room_id' => $this->room->id, 'tenancy_id' => $this->tenancy->id, 'user_id' => $this->kim->id,
        'name' => $this->kim->name, 'joined_on' => $this->tenancy->moved_in_on->toDateString(),
    ]);

    $this->list = "/api/v1/rooms/{$this->room->id}/occupants";
    $this->person = fn (array $over = []) => array_merge([
        'name' => 'Ericka Cruz',
        'joined_on' => now('Asia/Manila')->subDays(5)->toDateString(),
        'emergency_contact_name' => 'Marta Cruz',
        'emergency_contact_relationship' => 'Mother',
        'emergency_contact_phone' => '0917 123 4567',
    ], $over);
});

it('lets the leader add someone but never shows emergency contacts to the leader', function () {
    $this->actingAs($this->kim, 'sanctum')
        ->postJson($this->list, ($this->person)())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ericka Cruz')
        ->assertJsonMissingPath('data.emergency_contact');

    $this->actingAs($this->kim, 'sanctum')->getJson($this->list)->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.emergency_contact');
    $this->actingAs($this->owner, 'sanctum')->getJson($this->list)->assertOk()
        ->assertJsonPath('data.1.emergency_contact.name', 'Marta Cruz');

    $this->getJson('/api/v1/owner/audit-log?event=room.occupant_added')->assertJsonPath('data.0.event', 'room.occupant_added');
});

it('shows emergency contacts to the owner and to caretakers of the property', function () {
    RoomOccupant::factory()->create(['room_id' => $this->room->id, 'joined_on' => now('Asia/Manila')->subDays(2)->toDateString()]);

    foreach ([$this->owner, $this->manager, $this->collector] as $staff) {
        $this->actingAs($staff, 'sanctum')->getJson($this->list)->assertOk()
            ->assertJsonPath('data.0.emergency_contact.name', $this->kimRow->emergency_contact_name);
    }
});

it('lets a Manager but not a Collector add, and refuses everyone outside the room', function () {
    $this->actingAs($this->collector, 'sanctum')->postJson($this->list, ($this->person)())->assertForbidden();
    $this->actingAs($this->manager, 'sanctum')->postJson($this->list, ($this->person)())->assertCreated();

    $otherOwner = User::factory()->owner()->create();
    $stranger = User::factory()->boarder()->create();
    foreach ([$otherOwner, $stranger] as $outsider) {
        $this->actingAs($outsider, 'sanctum')->getJson($this->list)->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->postJson($this->list, ($this->person)())->assertNotFound();
    }
    expect(RoomOccupant::where('room_id', $this->room->id)->count())->toBe(2);
});

it('keeps to the room\'s capacity until the owner raises it', function () {
    $this->actingAs($this->kim, 'sanctum');
    $this->postJson($this->list, ($this->person)(['name' => 'Two']))->assertCreated();
    $this->postJson($this->list, ($this->person)(['name' => 'Three']))->assertCreated();

    $this->postJson($this->list, ($this->person)(['name' => 'Four']))->assertUnprocessable()->assertJsonValidationErrors(['room']);

    $this->actingAs($this->owner, 'sanctum')->patchJson('/api/v1/units/'.$this->room->units->first()->id, ['capacity' => 4])->assertOk();
    $this->actingAs($this->kim, 'sanctum')->postJson($this->list, ($this->person)(['name' => 'Four']))->assertCreated();
});

it('refuses occupants in a bedspace room or a room nobody has moved into', function () {
    $bedProperty = makeProperty($this->owner);
    $bedRoom = $bedProperty->rooms()->first();
    $emptyProperty = makeProperty($this->owner, ['rental_mode' => 'whole']);
    $emptyRoom = $emptyProperty->rooms()->first();

    $this->actingAs($this->owner, 'sanctum');
    $this->postJson("/api/v1/rooms/{$bedRoom->id}/occupants", ($this->person)())->assertUnprocessable()->assertJsonValidationErrors(['room']);
    $this->postJson("/api/v1/rooms/{$emptyRoom->id}/occupants", ($this->person)())->assertUnprocessable()->assertJsonValidationErrors(['room']);
});

it('checks the join date and the emergency contact', function () {
    $this->actingAs($this->owner, 'sanctum');

    $this->postJson($this->list, ($this->person)(['joined_on' => now('Asia/Manila')->addDay()->toDateString()]))
        ->assertUnprocessable()->assertJsonValidationErrors(['joined_on']);
    $this->postJson($this->list, ($this->person)(['joined_on' => now('Asia/Manila')->subDays(40)->toDateString()]))
        ->assertUnprocessable()->assertJsonValidationErrors(['joined_on']); // before the room was moved into
    $this->postJson($this->list, ['name' => 'No contact', 'joined_on' => now('Asia/Manila')->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['emergency_contact_name', 'emergency_contact_phone']);
});

it('links an existing account by email and refuses an unknown or suspended one', function () {
    $this->actingAs($this->owner, 'sanctum');
    $ben = User::factory()->boarder()->create();
    $gone = User::factory()->boarder()->suspended()->create();

    $this->postJson($this->list, ($this->person)(['email' => 'nobody@example.com']))->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson($this->list, ($this->person)(['email' => $gone->email]))->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson($this->list, ($this->person)(['email' => $ben->email]))->assertCreated()->assertJsonPath('data.has_account', true);
    $this->postJson($this->list, ($this->person)(['email' => $ben->email]))->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('lets the leader mark someone as left, but only change their name, phone and leave date', function () {
    $this->actingAs($this->kim, 'sanctum');
    $id = $this->postJson($this->list, ($this->person)())->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/occupants/{$id}", [
        'left_on' => now('Asia/Manila')->subDay()->toDateString(),
        'name' => 'Ericka C.',
        'emergency_contact_name' => 'Changed by the leader',
    ])->assertOk()->assertJsonPath('data.is_staying', false)->assertJsonPath('data.name', 'Ericka C.');

    $occupant = RoomOccupant::find($id);
    expect($occupant->emergency_contact_name)->toBe('Marta Cruz');

    $this->patchJson("/api/v1/occupants/{$id}", ['left_on' => null])->assertUnprocessable(); // the leader cannot bring someone back
    $this->patchJson("/api/v1/occupants/{$id}", ['left_on' => now('Asia/Manila')->addDay()->toDateString()])->assertUnprocessable();
});

it('lets the owner change everything and bring someone back, while the room has space', function () {
    $this->actingAs($this->owner, 'sanctum');
    $a = $this->postJson($this->list, ($this->person)(['name' => 'A']))->json('data.id');
    $this->postJson($this->list, ($this->person)(['name' => 'B']))->assertCreated();
    $this->patchJson("/api/v1/occupants/{$a}", ['left_on' => now('Asia/Manila')->toDateString()])->assertOk();
    $this->postJson($this->list, ($this->person)(['name' => 'C']))->assertCreated(); // takes A's place

    $this->patchJson("/api/v1/occupants/{$a}", ['left_on' => null])->assertUnprocessable()->assertJsonValidationErrors(['room']);

    $this->patchJson("/api/v1/occupants/{$this->kimRow->id}", ['emergency_contact_phone' => '0999 111 2222'])
        ->assertOk()->assertJsonPath('data.emergency_contact.phone', '0999 111 2222');
    $this->getJson('/api/v1/owner/audit-log?event=room.occupant_updated')->assertJsonPath('data.0.event', 'room.occupant_updated');
});

it('never removes or marks as left the person who rents the room', function () {
    $this->actingAs($this->owner, 'sanctum');

    $this->patchJson("/api/v1/occupants/{$this->kimRow->id}", ['left_on' => now('Asia/Manila')->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['left_on']);
    $this->deleteJson("/api/v1/occupants/{$this->kimRow->id}")->assertUnprocessable()->assertJsonValidationErrors(['occupant']);

    expect(RoomOccupant::find($this->kimRow->id)->left_on)->toBeNull();
});

it('lets the owner remove an occupant but not the leader', function () {
    $id = $this->actingAs($this->kim, 'sanctum')->postJson($this->list, ($this->person)())->json('data.id');

    $this->deleteJson("/api/v1/occupants/{$id}")->assertForbidden();
    $this->actingAs($this->collector, 'sanctum')->deleteJson("/api/v1/occupants/{$id}")->assertForbidden();
    $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/occupants/{$id}")->assertOk();

    expect(RoomOccupant::find($id))->toBeNull();
    $this->getJson('/api/v1/owner/audit-log?event=room.occupant_removed')->assertJsonPath('data.0.event', 'room.occupant_removed');
});

it('does not let the leader of one room touch another room', function () {
    $otherProperty = makeProperty($this->owner, ['rental_mode' => 'whole']);
    $otherRoom = $otherProperty->rooms()->first();
    $otherTenant = User::factory()->boarder()->create();
    Tenancy::factory()->forUnit($otherRoom->units->first())->create(['tenant_id' => $otherTenant->id]);
    $theirs = RoomOccupant::factory()->create(['room_id' => $otherRoom->id]);

    $this->actingAs($this->kim, 'sanctum');
    $this->getJson("/api/v1/rooms/{$otherRoom->id}/occupants")->assertNotFound();
    $this->postJson("/api/v1/rooms/{$otherRoom->id}/occupants", ($this->person)())->assertNotFound();
    $this->patchJson("/api/v1/occupants/{$theirs->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/occupants/{$theirs->id}")->assertNotFound();
});

it('stops a former leader from adding people', function () {
    RoomLeader::current()->where('room_id', $this->room->id)->update(['ended_on' => now('Asia/Manila')->toDateString()]);

    $this->actingAs($this->kim, 'sanctum')->postJson($this->list, ($this->person)())->assertNotFound();
});
