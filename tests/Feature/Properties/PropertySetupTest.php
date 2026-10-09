<?php

use App\Enums\UnitStatus;
use App\Models\PropertySettings;
use App\Models\RentableUnit;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => $this->owner = User::factory()->owner()->create());

it('creates a bedspace property with numbered beds, rents and default settings', function () {
    $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/properties', [
        'name' => 'Santos Boarding House',
        'type' => 'boarding_house',
        'rental_mode' => 'bedspaces',
        'city' => 'Manila',
        'units' => ['count' => 6, 'label_pattern' => 'Room A – Bed {n}', 'rent_centavos' => 180000],
    ])->assertCreated();

    $response->assertJsonPath('data.rental_mode', 'bedspaces')
        ->assertJsonPath('data.is_published', false)
        ->assertJsonPath('data.my_role', 'owner')
        ->assertJsonPath('data.counts.units', 6)
        ->assertJsonPath('data.counts.available', 6)
        ->assertJsonPath('data.units.0.label', 'Room A – Bed 1')
        ->assertJsonPath('data.units.5.label', 'Room A – Bed 6')
        ->assertJsonPath('data.units.0.rent_centavos', 180000);

    $propertyId = $response->json('data.id');
    $settings = PropertySettings::firstWhere('property_id', $propertyId);
    expect($settings->grace_days)->toBe(3)
        ->and(Activity::where('event', 'property.created')->count())->toBe(1);
});

it('creates a whole room property (a house or studio) with exactly one unit and its max occupants', function () {
    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/properties', [
        'name' => 'Mini House', 'type' => 'mini_house', 'rental_mode' => 'whole',
        'units' => ['capacity' => 4, 'rent_centavos' => 1200000],
    ])->assertCreated()
        ->assertJsonPath('data.counts.units', 1)
        ->assertJsonPath('data.units.0.kind', 'whole')
        ->assertJsonPath('data.units.0.capacity', 4)
        ->assertJsonPath('data.units.0.rent_centavos', 1200000);
});

it('only lets owners add properties', function () {
    $boarder = User::factory()->boarder()->create();

    $this->actingAs($boarder, 'sanctum')->postJson('/api/v1/properties', [
        'name' => 'X', 'type' => 'dorm', 'rental_mode' => 'whole',
    ])->assertForbidden();
});

it('adds bedspaces in bulk, continuing the numbering', function () {
    $property = makeProperty($this->owner);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/rooms/{$property->rooms()->first()->id}/bedspaces", ['count' => 2, 'rent_centavos' => 150000])
        ->assertCreated()
        ->assertJsonPath('data.0.label', 'Bed 4')
        ->assertJsonPath('data.1.label', 'Bed 5');

    expect($property->units()->count())->toBe(5);
});

it('switches the rental mode of a room only while no unit is in use, archiving the old units', function () {
    $property = makeProperty($this->owner);
    $room = $property->rooms()->first();
    $oldIds = $property->units()->pluck('id');

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/rooms/{$room->id}/rental-mode", [
            'rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 600000],
        ])->assertOk()
        ->assertJsonPath('data.rental_mode', 'whole')
        ->assertJsonPath('data.counts.units', 1);

    expect($property->fresh()->rentalModeSummary())->toBe('whole');
    expect(RentableUnit::withTrashed()->whereIn('id', $oldIds)->whereNotNull('deleted_at')->count())->toBe(3);

    // Someone moves in: switching back is refused.
    $property->units()->first()->forceFill(['status' => UnitStatus::Occupied])->save();
    $this->postJson("/api/v1/rooms/{$room->id}/rental-mode", ['rental_mode' => 'bedspaces', 'units' => ['count' => 2]])
        ->assertUnprocessable()->assertJsonValidationErrors(['mode']);
});

it('protects bedspaces in use, the last bedspace, and whole-room units from removal', function () {
    $property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 100000]]);
    [$a, $b] = $property->units()->get()->all();
    $a->forceFill(['status' => UnitStatus::Reserved])->save();

    $this->actingAs($this->owner, 'sanctum');
    $this->deleteJson("/api/v1/units/{$a->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/units/{$b->id}")->assertOk();

    $a->forceFill(['status' => UnitStatus::Available])->save();
    $this->deleteJson("/api/v1/units/{$a->id}")->assertUnprocessable(); // last one

    $whole = makeProperty($this->owner, ['rental_mode' => 'whole']);
    $this->deleteJson('/api/v1/units/'.$whole->units()->first()->id)->assertUnprocessable();
});

it('marks a unit not ready with a reason, and back', function () {
    $unit = makeProperty($this->owner)->units()->first();

    $this->actingAs($this->owner, 'sanctum')
        ->patchJson("/api/v1/units/{$unit->id}/not-ready", ['not_ready' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason']);

    $this->patchJson("/api/v1/units/{$unit->id}/not-ready", ['not_ready' => true, 'reason' => 'Repainting until Oct 10'])
        ->assertOk()->assertJsonPath('data.not_ready', true);

    $this->patchJson("/api/v1/units/{$unit->id}/not-ready", ['not_ready' => false])
        ->assertOk()->assertJsonPath('data.not_ready_reason', null);
});

it('deletes a property only when no unit is in use (owner only)', function () {
    $property = makeProperty($this->owner);
    $property->units()->first()->forceFill(['status' => UnitStatus::Occupied])->save();

    $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/properties/{$property->id}")
        ->assertStatus(409)->assertJsonPath('code', 'property_in_use');

    $property->units()->update(['status' => 'available']);
    $this->deleteJson("/api/v1/properties/{$property->id}")->assertOk();
    expect($property->fresh()->trashed())->toBeTrue();
});

it('validates a Philippine map pin', function () {
    $property = makeProperty($this->owner);

    $this->actingAs($this->owner, 'sanctum')
        ->patchJson("/api/v1/properties/{$property->id}", ['latitude' => 51.5, 'longitude' => -0.12])
        ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);

    $this->patchJson("/api/v1/properties/{$property->id}", ['latitude' => 14.6091, 'longitude' => 120.9894])
        ->assertOk()->assertJsonPath('data.latitude', 14.6091);
});
