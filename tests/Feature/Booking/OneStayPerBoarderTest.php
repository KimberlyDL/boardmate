<?php

use App\Enums\ApplicationStatus;
use App\Enums\NoticeStatus;
use App\Enums\UnitStatus;
use App\Models\BookingApplication;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/*
| One account, one person, one stay. While Kim stays at Santos House she
| cannot apply elsewhere (to book for a brother or cousin, they need their
| own account) until she gives notice; and nobody can be moved in a second
| time while a stay is running.
*/

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->home = makeProperty($this->owner, ['details' => ['name' => 'Santos House'], 'units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $this->other = makeProperty($this->owner, ['details' => ['name' => 'Reyes Dorm'], 'units' => ['count' => 2, 'rent_centavos' => 200000]]);
    foreach ([$this->home, $this->other] as $p) {
        $p->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
    }
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $unit = $this->home->units()->first();
    $this->stay = Tenancy::factory()->forUnit($unit)->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
    $unit->forceFill(['status' => UnitStatus::Occupied])->save();
});

afterEach(fn () => Date::setTestNow());

function applyElsewhere()
{
    return test()->actingAs(test()->kim, 'sanctum')->postJson('/api/v1/listings/'.test()->other->id.'/applications', [
        'planned_move_in_on' => '2026-11-01', 'contact_phone' => '0917 123 4567', 'emergency_contact_name' => 'Rosa Reyes', 'emergency_contact_phone' => '0917 555 0000',
    ]);
}

it('refuses to apply elsewhere while a stay is running, with a clear message', function () {
    applyElsewhere()->assertUnprocessable()
        ->assertJsonPath('code', 'has_stay')
        ->assertJsonPath('message', 'You are staying at Santos House. To book for someone else, they need their own DormLink account. To move yourself, give notice first.');
    expect(BookingApplication::count())->toBe(0);
});

it('allows applying once notice is given, waiting for approval or approved', function () {
    TenancyNotice::factory()->forTenancy($this->stay)->create(['end_on' => '2026-11-30', 'status' => NoticeStatus::Pending]);
    applyElsewhere()->assertCreated();

    BookingApplication::query()->delete();
    $this->stay->update(['status' => 'notice_given', 'end_on' => '2026-11-30']);
    applyElsewhere()->assertCreated();
});

it('refuses a second move-in while a stay is running, from a reservation or as a walk-in', function () {
    // A reservation made before the rule (or by staff mistake) still cannot be moved in.
    $bed = $this->other->units()->first();
    $reservation = (new BookingApplication(['planned_move_in_on' => '2026-10-25', 'contact_phone' => '0917 123 4567']))
        ->forceFill(['property_id' => $this->other->id, 'boarder_id' => $this->kim->id, 'room_id' => $bed->room_id, 'unit_id' => $bed->id,
            'status' => ApplicationStatus::Approved, 'reserved_until' => '2026-11-01']);
    $reservation->save();
    $bed->forceFill(['status' => UnitStatus::Reserved])->save();
    $moveIn = [
        'moved_in_on' => '2026-10-25',
        'emergency_contact' => ['name' => 'Marta', 'phone' => '0917 555 0000'],
        'deposit' => ['amount_centavos' => 200000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 200000, 'method' => 'cash'],
    ];
    $message = 'Kim already has a tenancy. End it before moving them in somewhere else.';

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$reservation->id}/move-in", $moveIn)
        ->assertUnprocessable()->assertJsonPath('errors.tenant.0', $message);
    // Without the reservation (a walk-in with one must use it), the walk-in is refused for the same reason.
    $reservation->forceFill(['status' => ApplicationStatus::Cancelled])->save();
    $walkInUnit = $this->other->units()->get()[1];
    $this->postJson("/api/v1/properties/{$this->other->id}/tenancies", $moveIn + ['email' => $this->kim->email, 'unit_id' => $walkInUnit->id])
        ->assertUnprocessable()->assertJsonPath('errors.tenant.0', $message);

    expect(Tenancy::count())->toBe(1)
        ->and($bed->fresh()->status)->toBe(UnitStatus::Reserved)
        ->and($walkInUnit->fresh()->status)->toBe(UnitStatus::Available);
});

it('refuses to apply while listed as staying in someone else\'s whole room, until marked as left', function () {
    // Kim moves out; Mia rents a whole room at Reyes Dorm and lists Kim as staying with her.
    $this->stay->update(['status' => 'ended', 'moved_out_on' => '2026-10-01']);
    $wholeRoom = makeProperty($this->owner, ['details' => ['name' => 'Cruz Rooms'], 'rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 400000]]);
    $room = $wholeRoom->rooms()->first();
    $mia = User::factory()->boarder()->create(['name' => 'Mia']);
    Tenancy::factory()->forUnit($room->units->first())->movedInOn('2026-10-01')->create(['tenant_id' => $mia->id]);
    $listed = RoomOccupant::factory()->create(['room_id' => $room->id, 'user_id' => $this->kim->id, 'name' => 'Kim', 'joined_on' => '2026-10-02']);

    applyElsewhere()->assertUnprocessable()
        ->assertJsonPath('code', 'listed_in_room')
        ->assertJsonPath('message', "You are listed as staying in Room {$room->label()} at Cruz Rooms. Ask Mia to mark you as left first.");

    $listed->update(['left_on' => '2026-10-20']);
    applyElsewhere()->assertCreated();
});
