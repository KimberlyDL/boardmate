<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\BookingApplication;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
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

    // Rooms: a house rented whole at ₱4,500 (fits 3), and a boarding house of 3 bedspaces at ₱2,000.
    $this->wholeProperty = makeProperty($this->owner, ['rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 450000]]);
    $this->bedProperty = makeProperty($this->owner, ['units' => ['count' => 3, 'rent_centavos' => 200000]]);
    foreach ([$this->wholeProperty, $this->bedProperty] as $property) {
        $property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
        assignCaretaker($property, $this->manager, 'manager');
        assignCaretaker($property, $this->collector, 'collector');
    }
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim', 'phone' => '0917 000 0001']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
});

afterEach(fn () => Date::setTestNow());

/** Kim-style: a boarder applies and the owner reserves a unit for them. Returns the application id. */
function reservedFor(User $boarder, $property, ?int $unitIndex = 0, array $approve = []): int
{
    $id = test()->actingAs($boarder, 'sanctum')->postJson("/api/v1/listings/{$property->id}/applications", [
        'planned_move_in_on' => '2026-10-27',
        'contact_phone' => '0917 123 4567',
    ])->assertCreated()->json('data.id');

    test()->actingAs(test()->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", [
        'unit_id' => $property->units()->get()[$unitIndex]->id,
    ] + $approve)->assertOk();

    return $id;
}

/** A complete move-in request for the ₱4,500 house: deposit and first rent recorded in full. */
function moveInBody(array $override = []): array
{
    return array_replace_recursive([
        'moved_in_on' => '2026-10-25',
        'emergency_contact' => ['name' => 'Marta Santos', 'relationship' => 'Mother', 'phone' => '0917 555 0000'],
        'deposit' => ['amount_centavos' => 450000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 450000, 'method' => 'cash'],
        'leader_consent' => true,
    ], $override);
}

function moveIn(int $applicationId, array $body, ?User $as = null)
{
    return test()->actingAs($as ?? test()->owner, 'sanctum')->postJson("/api/v1/applications/{$applicationId}/move-in", $body);
}

it('moves Kim into a whole room at the discounted rent', function () {
    $id = reservedFor($this->kim, $this->wholeProperty, 0, ['occupants' => [['name' => 'Ericka Cruz']]]);
    $unit = $this->wholeProperty->units()->first();
    $discount = [
        'discount' => ['kind' => 'fixed', 'value' => 50000], // 4,500 - 500
        'deposit' => ['amount_centavos' => 400000],
        'first_rent' => ['amount_centavos' => 400000],
    ];

    // The form first asks what it will cost: rent 4,500, discount 500, so deposit and first rent are 4,000 each.
    $this->getJson("/api/v1/properties/{$this->wholeProperty->id}/tenancies/preview?unit_id={$unit->id}&discount_kind=fixed&discount_value=50000")
        ->assertOk()
        ->assertJsonPath('data.rent_centavos', 450000)
        ->assertJsonPath('data.discount_centavos', 50000)
        ->assertJsonPath('data.deposit_centavos', 400000)
        ->assertJsonPath('data.first_rent_centavos', 400000);

    moveIn($id, moveInBody($discount + ['occupants' => [[
        'name' => 'Ericka Cruz', 'emergency_contact_name' => 'Marta Cruz', 'emergency_contact_phone' => '0918 111 2222',
    ]]]))
        ->assertCreated()
        ->assertJsonPath('data.moved_in_on', '2026-10-25')
        ->assertJsonPath('data.first_day_on', '2026-10-26')
        ->assertJsonPath('data.anchor_day', 25)
        ->assertJsonPath('data.next_rent_due_on', '2026-11-25')
        ->assertJsonPath('data.rent_after_discount_centavos', 400000)
        ->assertJsonPath('data.discount.label', 'Agreed rate')
        ->assertJsonPath('data.deposit_paid_centavos', 400000)
        ->assertJsonPath('data.first_rent_paid_centavos', 400000)
        ->assertJsonPath('data.activation_override', null);

    $tenancy = Tenancy::sole();
    expect($unit->fresh()->status->value)->toBe('occupied')
        ->and(BookingApplication::find($id)->status->value)->toBe('moved_in')
        ->and($tenancy->tenant_id)->toBe($this->kim->id)
        ->and($tenancy->payments)->toHaveCount(2)
        ->and($tenancy->discounts)->toHaveCount(1);

    // Kim leads the room, with her consent recorded, and sits on the occupant list with Ericka.
    $room = $this->wholeProperty->rooms()->first();
    expect($room->leader->user_id)->toBe($this->kim->id)
        ->and(RoomOccupant::where('room_id', $room->id)->orderBy('id')->pluck('name')->all())->toBe(['Kim', 'Ericka Cruz'])
        ->and(RoomOccupant::where('tenancy_id', $tenancy->id)->sole()->emergency_contact_name)->toBe('Marta Santos');

    $this->getJson('/api/v1/owner/audit-log?event=tenancy.started')->assertJsonPath('data.0.event', 'tenancy.started');
});

it('takes the deposit from the deposit rule', function () {
    $unit = $this->wholeProperty->units()->first();
    $url = "/api/v1/properties/{$this->wholeProperty->id}/tenancies/preview?unit_id={$unit->id}";
    $this->actingAs($this->owner, 'sanctum');

    $this->getJson($url)->assertJsonPath('data.deposit_centavos', 450000); // one month's rent, no discount
    $this->getJson("{$url}&discount_kind=percent&discount_value=1000")
        ->assertJsonPath('data.discount_centavos', 45000)->assertJsonPath('data.deposit_centavos', 405000);

    $this->putJson("/api/v1/properties/{$this->wholeProperty->id}/settings", ['deposit_rule' => 'fixed_amount', 'deposit_fixed_centavos' => 300000])->assertOk();
    $this->getJson($url)->assertJsonPath('data.deposit_centavos', 300000)->assertJsonPath('data.first_rent_centavos', 450000);

    $this->putJson("/api/v1/properties/{$this->wholeProperty->id}/settings", ['deposit_rule' => 'none'])->assertOk();
    $this->getJson($url)->assertJsonPath('data.deposit_centavos', 0);
});

it('refuses a discount larger than the rent', function () {
    $unit = $this->wholeProperty->units()->first();
    $this->actingAs($this->owner, 'sanctum')
        ->getJson("/api/v1/properties/{$this->wholeProperty->id}/tenancies/preview?unit_id={$unit->id}&discount_kind=fixed&discount_value=500000")
        ->assertUnprocessable()->assertJsonValidationErrors(['discount.value']);
});

it('needs the deposit and first rent recorded in full, or an override with a reason', function () {
    $id = reservedFor($this->kim, $this->wholeProperty);
    $short = moveInBody(['first_rent' => ['amount_centavos' => 100000]]);
    unset($short['deposit']);

    moveIn($id, $short)->assertUnprocessable()->assertJsonValidationErrors(['activation']);
    expect(Tenancy::count())->toBe(0)
        ->and($this->wholeProperty->units()->first()->status->value)->toBe('reserved');

    // A Collector cannot move people in at all; a Manager can, with a reason.
    moveIn($id, $short + ['override_reason' => 'Trusted, pays Friday'], $this->collector)->assertForbidden();
    moveIn($id, $short, $this->manager)->assertUnprocessable();
    moveIn($id, $short + ['override_reason' => 'Trusted, pays Friday'], $this->manager)
        ->assertCreated()->assertJsonPath('data.activation_override.reason', 'Trusted, pays Friday');

    $tenancy = Tenancy::sole();
    expect($tenancy->activation_overridden_by)->toBe($this->manager->id)
        ->and($tenancy->payments)->toHaveCount(1); // only the first rent that was actually received
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/owner/audit-log?event=tenancy.activation_overridden')
        ->assertJsonPath('data.0.event', 'tenancy.activation_overridden');
});

it('moves in without payments when the property does not require activation', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson("/api/v1/properties/{$this->wholeProperty->id}/settings", ['activation_required' => false])->assertOk();
    $id = reservedFor($this->kim, $this->wholeProperty);

    $body = moveInBody();
    unset($body['deposit'], $body['first_rent']);
    moveIn($id, $body)->assertCreated();

    expect(Tenancy::sole()->payments)->toHaveCount(0);
});

it('checks the reservation and the date', function () {
    $id = reservedFor($this->kim, $this->wholeProperty);

    moveIn($id, moveInBody(['moved_in_on' => '2026-10-26']))->assertUnprocessable()->assertJsonValidationErrors(['moved_in_on']);
    moveIn($id, moveInBody(['moved_in_on' => '2026-09-20']))->assertUnprocessable()->assertJsonValidationErrors(['moved_in_on']);
    moveIn($id, moveInBody(['emergency_contact' => ['name' => '']]))->assertUnprocessable()->assertJsonValidationErrors(['emergency_contact.name']);
    moveIn($id, moveInBody(['leader_consent' => false]))->assertUnprocessable()->assertJsonValidationErrors(['leader_consent']);

    // Moved in once, a second try is refused.
    moveIn($id, moveInBody())->assertCreated();
    moveIn($id, moveInBody())->assertUnprocessable()->assertJsonValidationErrors(['application']);
    expect(Tenancy::count())->toBe(1);

    // A pending application is not a reservation.
    $pending = $this->actingAs($this->ben, 'sanctum')->postJson("/api/v1/listings/{$this->bedProperty->id}/applications", [
        'planned_move_in_on' => '2026-10-27', 'contact_phone' => '0917 123 4567',
    ])->json('data.id');
    moveIn($pending, moveInBody())->assertUnprocessable()->assertJsonValidationErrors(['application']);
});

it('refuses an expired reservation', function () {
    $id = reservedFor($this->kim, $this->wholeProperty);
    Date::setTestNow(CarbonImmutable::parse('2026-11-05 08:00', 'Asia/Manila')); // reserved until Nov 3

    moveIn($id, moveInBody(['moved_in_on' => '2026-11-05']))->assertUnprocessable()->assertJsonValidationErrors(['application']);
});

it('needs emergency contacts for the people to stay, and changes nothing when something is missing', function () {
    $id = reservedFor($this->kim, $this->wholeProperty, 0, ['occupants' => [['name' => 'Ericka Cruz']]]);

    moveIn($id, moveInBody())->assertUnprocessable()->assertJsonValidationErrors(['occupants.0.emergency_contact_name', 'occupants.0.emergency_contact_phone']);

    expect(Tenancy::count())->toBe(0)
        ->and(RoomLeader::count())->toBe(0)
        ->and(RoomOccupant::count())->toBe(0)
        ->and(BookingApplication::find($id)->status->value)->toBe('approved')
        ->and($this->wholeProperty->units()->first()->status->value)->toBe('reserved');
});

it('moves bedspacers in separately, and names one the room\'s leader', function () {
    $kimApp = reservedFor($this->kim, $this->bedProperty, 0, ['leader' => true]);
    $benApp = reservedFor($this->ben, $this->bedProperty, 1);

    // Each bedspacer pays their own deposit: ₱2,000 here.
    $bed = ['deposit' => ['amount_centavos' => 200000], 'first_rent' => ['amount_centavos' => 200000]];
    moveIn($kimApp, moveInBody($bed + ['leader_consent' => false]))->assertUnprocessable()->assertJsonValidationErrors(['leader_consent']);
    moveIn($kimApp, moveInBody($bed))->assertCreated();
    moveIn($benApp, moveInBody($bed + ['make_leader' => true]))->assertUnprocessable()->assertJsonValidationErrors(['make_leader']);
    moveIn($benApp, moveInBody($bed))->assertCreated();

    $room = $this->bedProperty->rooms()->first();
    expect($room->leader->user_id)->toBe($this->kim->id)
        ->and(Tenancy::count())->toBe(2)
        ->and(RoomOccupant::count())->toBe(0) // a bedspace room lists tenancies, not occupants
        ->and($this->bedProperty->units()->where('status', 'occupied')->count())->toBe(2);
});

it('moves a walk-in in by their account email', function () {
    $unit = $this->bedProperty->units()->first();
    $url = "/api/v1/properties/{$this->bedProperty->id}/tenancies";
    $body = moveInBody(['deposit' => ['amount_centavos' => 200000], 'first_rent' => ['amount_centavos' => 200000]]);
    $this->actingAs($this->owner, 'sanctum');

    $this->postJson($url, $body + ['unit_id' => $unit->id, 'email' => 'nobody@example.com'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson($url, $body + ['unit_id' => $this->wholeProperty->units()->first()->id, 'email' => $this->ben->email])
        ->assertUnprocessable()->assertJsonValidationErrors(['unit_id']); // another property's unit

    $this->postJson($url, $body + ['unit_id' => $unit->id, 'email' => strtoupper($this->ben->email)])
        ->assertCreated()->assertJsonPath('data.tenant.name', 'Ben');

    expect(Tenancy::sole()->booking_application_id)->toBeNull()
        ->and($unit->fresh()->status->value)->toBe('occupied');

    // The bed is taken now, and Ben already lives somewhere.
    $this->postJson($url, $body + ['unit_id' => $unit->id, 'email' => $this->kim->email])->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);
    $this->postJson($url, $body + ['unit_id' => $this->bedProperty->units()->get()[1]->id, 'email' => $this->ben->email])
        ->assertUnprocessable()->assertJsonValidationErrors(['tenant']);
});

it('refuses a walk-in who holds a reservation, and a suspended account', function () {
    reservedFor($this->kim, $this->wholeProperty);
    $suspended = User::factory()->boarder()->suspended()->create();
    $bed = $this->bedProperty->units()->first();
    $body = moveInBody(['deposit' => ['amount_centavos' => 200000], 'first_rent' => ['amount_centavos' => 200000]]);

    $this->actingAs($this->owner, 'sanctum');
    $this->postJson("/api/v1/properties/{$this->bedProperty->id}/tenancies", $body + ['unit_id' => $bed->id, 'email' => $this->kim->email])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson("/api/v1/properties/{$this->bedProperty->id}/tenancies", $body + ['unit_id' => $bed->id, 'email' => $suspended->email])
        ->assertUnprocessable()->assertJsonValidationErrors(['tenant']);
});

it('withdraws the tenant\'s pending applications when a walk-in moves in', function () {
    // Kim has applied elsewhere (no reservation yet), then is walked into a bedspace.
    $other = makeProperty(User::factory()->owner()->create(), ['units' => ['count' => 1, 'rent_centavos' => 150000]]);
    $other->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
    $pending = $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/listings/{$other->id}/applications", [
        'planned_move_in_on' => '2026-10-27', 'contact_phone' => '0917 123 4567',
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->bedProperty->id}/tenancies", moveInBody([
        'deposit' => ['amount_centavos' => 200000], 'first_rent' => ['amount_centavos' => 200000],
    ]) + ['unit_id' => $this->bedProperty->units()->first()->id, 'email' => $this->kim->email])->assertCreated();

    expect(BookingApplication::find($pending)->status->value)->toBe('cancelled')
        ->and(BookingApplication::find($pending)->closed_reason)->toBe("You moved into {$this->bedProperty->name}.");
});

it('keeps every move-in endpoint inside the owner\'s own properties', function () {
    $id = reservedFor($this->kim, $this->wholeProperty);
    $otherOwner = User::factory()->owner()->create();
    $stranger = User::factory()->boarder()->create();
    $unit = $this->wholeProperty->units()->first();

    foreach ([$otherOwner, $stranger] as $outsider) {
        moveIn($id, moveInBody(), $outsider)->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/properties/{$this->wholeProperty->id}/tenancies", moveInBody() + ['unit_id' => $unit->id, 'email' => $this->ben->email])->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/properties/{$this->wholeProperty->id}/tenancies")->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/properties/{$this->wholeProperty->id}/tenancies/preview?unit_id={$unit->id}")->assertNotFound();
    }
    expect(Tenancy::count())->toBe(0);
});

it('lists tenancies for staff and shows a tenant only their own', function () {
    $kimId = reservedFor($this->kim, $this->wholeProperty);
    moveIn($kimId, moveInBody())->assertCreated();
    $tenancy = Tenancy::sole();

    // Staff, including a Collector, see the list and the details.
    foreach ([$this->owner, $this->manager, $this->collector] as $staff) {
        $this->actingAs($staff, 'sanctum')->getJson("/api/v1/properties/{$this->wholeProperty->id}/tenancies")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tenant.name', 'Kim')->assertJsonPath('data.0.payments.0.kind', 'deposit');
        $this->getJson("/api/v1/tenancies/{$tenancy->id}")->assertOk()->assertJsonPath('data.tenant.email', $this->kim->email);
    }

    // Kim sees her stay without the owner's bookkeeping; Ben cannot see it at all.
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/tenancies')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.room.id', $tenancy->room_id)->assertJsonPath('data.0.room.rental_mode', 'whole')
        ->assertJsonPath('data.0.is_leader', true)->assertJsonMissingPath('data.0.payments')->assertJsonMissingPath('data.0.tenant.email');
    $this->getJson("/api/v1/tenancies/{$tenancy->id}")->assertOk()->assertJsonMissingPath('data.payments');
    $this->actingAs($this->ben, 'sanctum')->getJson("/api/v1/tenancies/{$tenancy->id}")->assertNotFound();
    $this->getJson('/api/v1/me/tenancies')->assertOk()->assertJsonCount(0, 'data');
});

it('lets the tenant or a Manager change the emergency contact, and keeps the occupant list in step', function () {
    moveIn(reservedFor($this->kim, $this->wholeProperty), moveInBody())->assertCreated();
    $tenancy = Tenancy::sole();
    $url = "/api/v1/tenancies/{$tenancy->id}/emergency-contact";
    $contact = ['name' => 'Pedro Santos', 'relationship' => 'Father', 'phone' => '0999 888 7777'];

    $this->actingAs($this->kim, 'sanctum')->putJson($url, $contact)->assertOk()->assertJsonPath('data.emergency_contact.name', 'Pedro Santos');
    expect(RoomOccupant::where('tenancy_id', $tenancy->id)->sole()->emergency_contact_phone)->toBe('0999 888 7777');

    $this->actingAs($this->manager, 'sanctum')->putJson($url, ['phone' => '0911 222 3333'] + $contact)->assertOk();
    $this->actingAs($this->collector, 'sanctum')->putJson($url, $contact)->assertForbidden();
    $this->actingAs($this->ben, 'sanctum')->putJson($url, $contact)->assertNotFound();
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/owner/audit-log?event=tenancy.emergency_contact_changed')
        ->assertJsonPath('data.0.event', 'tenancy.emergency_contact_changed');
});
