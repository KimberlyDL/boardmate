<?php

use App\Models\BookingApplication;
use App\Models\Room;
use App\Models\User;
use App\Services\Notifications\DormLinkNotification;
use App\Services\Properties\UnitStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    Notification::fake();

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 250000]]);
    $this->property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
    $this->room = $this->property->rooms()->first();
    $this->room->update(['door_no' => '201']);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
});

afterEach(fn () => Date::setTestNow());

function offerWhole(int $rent = 600000, array $extra = [])
{
    return test()->actingAs(test()->owner, 'sanctum')->putJson('/api/v1/rooms/'.test()->room->id.'/whole-option', ['rent_centavos' => $rent] + $extra);
}

function applyTo(User $boarder, array $data = [])
{
    return test()->actingAs($boarder, 'sanctum')->postJson('/api/v1/listings/'.test()->property->id.'/applications', $data + [
        'planned_move_in_on' => '2026-10-20', 'contact_phone' => '0917 123 4567', 'room_id' => test()->room->id,
    ]);
}

function publicRoom(): array
{
    return test()->getJson('/api/v1/listings/'.test()->property->id)->assertOk()->json('data.listing.rooms.0');
}

it('lets the owner offer a bedspace room as a whole, but not a room already rented whole', function () {
    offerWhole(600000)->assertOk()
        ->assertJsonPath('message', 'Room 201 can also be rented whole.')
        ->assertJsonPath('data.counts.units', 2)
        ->assertJsonPath('data.units.2.is_whole_option', true)
        ->assertJsonPath('data.units.2.capacity', 2)
        ->assertJsonPath('data.units.2.rent_centavos', 600000);

    // Saving again re-prices the same option.
    offerWhole(650000, ['capacity' => 3])->assertOk()->assertJsonPath('data.units.2.rent_centavos', 650000)->assertJsonPath('data.units.2.capacity', 3);
    expect($this->room->units()->where('is_whole_option', true)->count())->toBe(1);

    expect(publicRoom())->toMatchArray(['total' => 2, 'availability_label' => '2 of 2 bedspaces available'])
        ->and(publicRoom()['whole_option'])->toBe(['available' => true, 'rent_centavos' => 650000, 'capacity' => 3]);

    $whole = makeProperty($this->owner, ['rental_mode' => 'whole'])->rooms()->first();
    $this->putJson("/api/v1/rooms/{$whole->id}/whole-option", ['rent_centavos' => 600000])->assertUnprocessable()->assertJsonValidationErrors(['room']);
});

it('stops offering the whole room once a bedspace is taken, and frees it again when the bed is free', function () {
    offerWhole();
    $id = applyTo($this->kim)->assertCreated()->json('data.id');
    $bed = $this->room->units()->where('is_whole_option', false)->first();
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $bed->id])->assertOk();

    expect(publicRoom()['whole_option']['available'])->toBeFalse()
        ->and(publicRoom()['status'])->toBe('available');
    applyTo($this->ben, ['whole' => true])->assertUnprocessable()->assertJsonPath('errors.whole.0', 'Room 201 cannot be rented as a whole right now.');

    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/me/applications/{$id}/cancel")->assertOk();
    expect(publicRoom()['whole_option']['available'])->toBeTrue();
});

it('rents the whole room to one boarder: the bedspaces are held back, and the room is led like a whole room', function () {
    offerWhole();
    $id = applyTo($this->kim, ['whole' => true])->assertCreated()->assertJsonPath('data.wants_whole', true)->json('data.id');
    Notification::assertSentTo($this->owner, DormLinkNotification::class, fn ($n) => str_contains($n->data['room_label'] ?? '', '201') && $n->data['whole'] === true);

    $option = $this->room->units()->where('is_whole_option', true)->first();
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", [
        'unit_id' => $option->id, 'occupants' => [['name' => 'Ericka Cruz']],
    ])->assertOk();

    expect(publicRoom())->toMatchArray(['status' => 'full', 'available' => 0])
        ->and($this->property->fresh()->bookableUnits()->count())->toBe(0);
    $this->getJson('/api/v1/listings')->assertJsonPath('data.0.is_full', true);
    applyTo($this->ben)->assertUnprocessable()->assertJsonValidationErrors(['property']);

    // Move-in: Kim leads the room and Ericka is listed as staying.
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/move-in", [
        'moved_in_on' => '2026-10-20',
        'emergency_contact' => ['name' => 'Marta', 'relationship' => 'Mother', 'phone' => '0917 555 0000'],
        'deposit' => ['amount_centavos' => 600000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 600000, 'method' => 'cash'],
        'leader_consent' => true,
        'occupants' => [['name' => 'Ericka Cruz', 'emergency_contact_name' => 'Rosa', 'emergency_contact_phone' => '0917 000 1111']],
    ])->assertCreated();

    $room = Room::find($this->room->id);
    expect($room->isLetWhole())->toBeTrue()
        ->and($room->leader()->first()->user_id)->toBe($this->kim->id)
        ->and($room->occupants()->pluck('name')->all())->toContain('Ericka Cruz');
    $this->getJson("/api/v1/properties/{$this->property->id}")->assertJsonPath('data.rooms.0.let_whole', true);
});

it('removes the whole-room option like a unit, but not while someone holds it', function () {
    offerWhole();
    $option = $this->room->units()->where('is_whole_option', true)->first();
    $id = applyTo($this->kim, ['whole' => true])->json('data.id');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => $option->id])->assertOk();

    $this->deleteJson("/api/v1/units/{$option->id}")->assertUnprocessable()->assertJsonPath('errors.unit.0', 'Someone has booked or rents this room as a whole.');

    BookingApplication::find($id)->forceFill(['status' => 'cancelled'])->save();
    app(UnitStatusService::class)->releaseReservation($option->id);
    $this->deleteJson("/api/v1/units/{$option->id}")->assertOk();

    expect(publicRoom()['whole_option'])->toBeNull()
        ->and($this->room->units()->where('blocked', true)->count())->toBe(0);
});
