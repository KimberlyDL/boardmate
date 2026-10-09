<?php

use App\Models\BookingApplication;
use App\Models\RoomLeader;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');

    $this->owner = User::factory()->owner()->create();
    $this->bedProperty = makeProperty($this->owner, ['units' => ['count' => 3, 'rent_centavos' => 200000]]);
    $this->wholeProperty = makeProperty($this->owner, ['rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 450000]]);
    foreach ([$this->bedProperty, $this->wholeProperty] as $property) {
        $property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
    }
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
});

/** A pending application by $boarder on $property, as the API would create it. */
function attendancePending(User $boarder, $property): int
{
    return test()->actingAs($boarder, 'sanctum')->postJson("/api/v1/listings/{$property->id}/applications", [
        'planned_move_in_on' => now('Asia/Manila')->addDays(5)->toDateString(),
        'contact_phone' => '0917 123 4567',
    ])->assertCreated()->json('data.id');
}

function attendanceApprove(int $id, array $body)
{
    return test()->actingAs(test()->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", $body);
}

it('notes who will stay when a whole room is reserved, and makes the applicant its leader', function () {
    $id = attendancePending($this->kim, $this->wholeProperty);
    $unit = $this->wholeProperty->units()->first();

    attendanceApprove($id, ['unit_id' => $unit->id, 'occupants' => [
        ['name' => 'Ericka Cruz', 'contact_phone' => '0918 111 2222', 'emergency_contact_name' => 'Marta Cruz', 'emergency_contact_phone' => '0917 000 1111'],
        ['name' => 'Third Person'],
    ]])
        ->assertOk()
        ->assertJsonPath('data.leader_on_move_in', true)
        ->assertJsonPath('data.planned_occupants.0.name', 'Ericka Cruz')
        ->assertJsonPath('data.planned_occupants.1.emergency_contact_name', null);

    $application = BookingApplication::find($id);
    expect($application->leader_on_move_in)->toBeTrue()
        ->and($application->planned_occupants)->toHaveCount(2);

    // The boarder sees the leader flag but not the people's contacts.
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/applications')
        ->assertJsonPath('data.0.leader_on_move_in', true)
        ->assertJsonMissingPath('data.0.planned_occupants');
});

it('refuses more people than the whole room fits, counting the applicant', function () {
    $id = attendancePending($this->kim, $this->wholeProperty);
    $unit = $this->wholeProperty->units()->first();

    attendanceApprove($id, ['unit_id' => $unit->id, 'occupants' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['occupants']);

    expect($unit->fresh()->status->value)->toBe('available')
        ->and(BookingApplication::find($id)->status->value)->toBe('pending');

    attendanceApprove($id, ['unit_id' => $unit->id, 'occupants' => [['name' => 'A'], ['name' => 'B']]])->assertOk();
});

it('lists people to stay only for rooms rented whole', function () {
    $id = attendancePending($this->kim, $this->bedProperty);

    attendanceApprove($id, ['unit_id' => $this->bedProperty->units()->first()->id, 'occupants' => [['name' => 'A']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['occupants']);
});

it('checks the people to stay', function () {
    $id = attendancePending($this->kim, $this->wholeProperty);
    $unit = $this->wholeProperty->units()->first();

    attendanceApprove($id, ['unit_id' => $unit->id, 'occupants' => [['contact_phone' => '0917 123 4567']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['occupants.0.name']);
    attendanceApprove($id, ['unit_id' => $unit->id, 'occupants' => [['name' => 'A', 'emergency_contact_name' => 'B']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['occupants.0.emergency_contact_phone']);
});

it('names the applicant leader of a bedspace room that has none', function () {
    $id = attendancePending($this->kim, $this->bedProperty);

    attendanceApprove($id, ['unit_id' => $this->bedProperty->units()->first()->id, 'leader' => true])
        ->assertOk()->assertJsonPath('data.leader_on_move_in', true);

    // Without the flag nothing is planned.
    $other = attendancePending($this->ben, $this->bedProperty);
    attendanceApprove($other, ['unit_id' => $this->bedProperty->units()->get()[1]->id])
        ->assertOk()->assertJsonPath('data.leader_on_move_in', false);
});

it('allows one planned leader per bedspace room, and frees the place when the reservation is cancelled', function () {
    $first = attendancePending($this->kim, $this->bedProperty);
    $second = attendancePending($this->ben, $this->bedProperty);
    [$bed1, $bed2] = [$this->bedProperty->units()->get()[0], $this->bedProperty->units()->get()[1]];

    attendanceApprove($first, ['unit_id' => $bed1->id, 'leader' => true])->assertOk();
    attendanceApprove($second, ['unit_id' => $bed2->id, 'leader' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['leader']);
    expect($bed2->fresh()->status->value)->toBe('available');

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$first}/cancel", ['reason' => 'Changed plans'])->assertOk();
    attendanceApprove($second, ['unit_id' => $bed2->id, 'leader' => true])->assertOk();
});

it('refuses to plan a leader in a room that already has one', function () {
    $room = $this->bedProperty->rooms()->first();
    RoomLeader::factory()->create(['room_id' => $room->id]);
    $id = attendancePending($this->kim, $this->bedProperty);

    attendanceApprove($id, ['unit_id' => $this->bedProperty->units()->first()->id, 'leader' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['leader']);
});

it('tells the reviewer which units are whole rooms, their capacity and whether the room has a leader', function () {
    RoomLeader::factory()->create(['room_id' => $this->bedProperty->rooms()->first()->id]);
    $id = attendancePending($this->kim, $this->bedProperty);

    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/applications/{$id}")
        ->assertOk()
        ->assertJsonPath('meta.bookable_units.0.kind', 'bedspace')
        ->assertJsonPath('meta.bookable_units.0.room_has_leader', true);

    $wholeId = attendancePending($this->ben, $this->wholeProperty);
    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/applications/{$wholeId}")
        ->assertJsonPath('meta.bookable_units.0.kind', 'whole')
        ->assertJsonPath('meta.bookable_units.0.capacity', 3)
        ->assertJsonPath('meta.bookable_units.0.room_has_leader', false);
});
