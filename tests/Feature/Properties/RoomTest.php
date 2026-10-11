<?php

use App\Enums\UnitStatus;
use App\Http\Resources\Listings\ListingResource;
use App\Models\Room;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
});

it('gives every new property one room whose units belong to it', function () {
    $room = $this->property->rooms()->get();

    expect($room)->toHaveCount(1)
        ->and($room->first()->number)->toBe(1)
        ->and($room->first()->code())->toBe('01')
        ->and($this->property->units()->where('room_id', $room->first()->id)->count())->toBe(3);
});

it('adds rooms rented whole or by bedspace in one property, with floor and code', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/rooms", [
            'rental_mode' => 'whole', 'floor' => 4, 'units' => ['capacity' => 2, 'rent_centavos' => 400000],
        ])->assertCreated()
        ->assertJsonPath('data.code', 'F4-02')
        ->assertJsonPath('data.rental_mode', 'whole')
        ->assertJsonPath('data.units.0.kind', 'whole')
        ->assertJsonPath('data.units.0.capacity', 2);

    $this->postJson("/api/v1/properties/{$this->property->id}/rooms", [
        'rental_mode' => 'bedspaces', 'floor' => 4, 'units' => ['count' => 4, 'rent_centavos' => 150000],
    ])->assertCreated()->assertJsonPath('data.code', 'F4-03');

    $this->getJson("/api/v1/properties/{$this->property->id}")
        ->assertOk()
        ->assertJsonPath('data.rental_mode', 'mixed')
        ->assertJsonPath('data.counts.rooms', 3)
        ->assertJsonPath('data.rooms.0.code', '01')
        ->assertJsonPath('data.rooms.2.floor', 4);
});

it('changes a room\'s floor and keeps the room number', function () {
    $room = $this->property->rooms()->first();

    $this->actingAs($this->owner, 'sanctum')
        ->patchJson("/api/v1/rooms/{$room->id}", ['floor' => 2])
        ->assertOk()->assertJsonPath('data.code', 'F2-01');

    expect(Room::find($room->id)->floor)->toBe(2);
});

it('sets a door number and who can stay, and calls the room by its door number', function () {
    $this->actingAs($this->owner, 'sanctum');
    $room = $this->property->rooms()->first();

    $this->postJson("/api/v1/properties/{$this->property->id}/rooms", [
        'rental_mode' => 'whole', 'floor' => 2, 'door_no' => '201', 'who_can_stay' => 'female',
        'units' => ['capacity' => 2, 'rent_centavos' => 400000],
    ])->assertCreated()
        ->assertJsonPath('message', 'Room 201 added.')
        ->assertJsonPath('data.code', 'F2-02')
        ->assertJsonPath('data.label', '201')
        ->assertJsonPath('data.who_can_stay_label', 'Female only');

    $this->patchJson("/api/v1/rooms/{$room->id}", ['door_no' => '2B'])
        ->assertOk()
        ->assertJsonPath('data.label', '2B')
        ->assertJsonPath('data.floor', null)
        ->assertJsonPath('data.who_can_stay', 'any');

    $this->patchJson("/api/v1/rooms/{$room->id}", ['door_no' => null])
        ->assertOk()->assertJsonPath('data.label', '01');
});

it('keeps door numbers unique within a property, ignoring case and removed rooms', function () {
    $this->actingAs($this->owner, 'sanctum');
    $room = $this->property->rooms()->first();
    $room->update(['door_no' => '2B']);

    $this->postJson("/api/v1/properties/{$this->property->id}/rooms", [
        'rental_mode' => 'whole', 'door_no' => '2b', 'units' => ['capacity' => 1, 'rent_centavos' => 300000],
    ])->assertUnprocessable()->assertJsonValidationErrors(['door_no']);

    // The same room can keep its own number, and another property can use it.
    $this->patchJson("/api/v1/rooms/{$room->id}", ['door_no' => '2B'])->assertOk();
    makeProperty($this->owner)->rooms()->first()->update(['door_no' => '2B']);

    $second = $this->postJson("/api/v1/properties/{$this->property->id}/rooms", [
        'rental_mode' => 'whole', 'door_no' => '3A', 'units' => ['capacity' => 1, 'rent_centavos' => 300000],
    ])->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/rooms/{$second}")->assertOk()->assertJsonPath('message', 'Room 3A removed.');
    $this->patchJson("/api/v1/rooms/{$room->id}", ['door_no' => '3A'])->assertOk();
});

it('never switches a room\'s mode because someone is staying, and refuses removing a room in use', function () {
    $this->actingAs($this->owner, 'sanctum');
    $second = $this->postJson("/api/v1/properties/{$this->property->id}/rooms", [
        'rental_mode' => 'whole', 'units' => ['capacity' => 2, 'rent_centavos' => 400000],
    ])->assertCreated()->json('data.id');
    $unit = Room::find($second)->units()->first();
    $unit->forceFill(['status' => UnitStatus::Occupied])->save();

    $this->postJson("/api/v1/rooms/{$second}/rental-mode", ['rental_mode' => 'bedspaces', 'units' => ['count' => 2]])
        ->assertUnprocessable()->assertJsonValidationErrors(['mode']);
    $this->deleteJson("/api/v1/rooms/{$second}")->assertStatus(409)->assertJsonPath('code', 'room_in_use');

    // The other room is free: it can switch and be removed.
    $first = $this->property->rooms()->first();
    $this->postJson("/api/v1/rooms/{$first->id}/rental-mode", ['rental_mode' => 'whole', 'units' => ['capacity' => 1]])->assertOk();
    $this->deleteJson("/api/v1/rooms/{$first->id}")->assertOk();
    expect($this->property->rooms()->count())->toBe(1);
});

it('keeps at least one room in a property', function () {
    $room = $this->property->rooms()->first();

    $this->actingAs($this->owner, 'sanctum')
        ->deleteJson("/api/v1/rooms/{$room->id}")
        ->assertUnprocessable()->assertJsonValidationErrors(['room']);
});

it('does not let one owner reach another owner\'s rooms', function () {
    $other = User::factory()->owner()->create();
    $room = $this->property->rooms()->first();

    $this->actingAs($other, 'sanctum');
    $this->patchJson("/api/v1/rooms/{$room->id}", ['floor' => 3])->assertNotFound();
    $this->getJson("/api/v1/properties/{$this->property->id}/rooms")->assertNotFound();
});

it('lists the whole-room and bedspace availability of a mixed property in the listing label', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/rooms", [
            'rental_mode' => 'whole', 'units' => ['capacity' => 2, 'rent_centavos' => 400000],
        ])->assertCreated();

    $label = (new ListingResource($this->property->fresh()->load(['rooms', 'units', 'photos', 'utilityAccounts', 'owner.ownerProfile'])))
        ->toArray(request())['availability']['label'];

    expect($label)->toBe('Whole room for up to 2 · 3 of 3 bedspaces available');
});
