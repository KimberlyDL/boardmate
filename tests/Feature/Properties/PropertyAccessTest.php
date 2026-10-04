<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\CaretakerInvitation;
use App\Models\OwnerCaretaker;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
| Owner / Manager / Collector on real properties, and owner data separation.
*/

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->unit = $this->property->units()->first();

    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');
});

/** status code each person gets for a request */
function codes(array $people, string $method, string $uri, array $data = []): array
{
    $out = [];
    foreach ($people as $name => $user) {
        $out[$name] = test()->actingAs($user, 'sanctum')->json($method, $uri, $data)->status();
    }

    return $out;
}

it('lets each person do exactly what their role allows', function () {
    $other = User::factory()->owner()->create();
    $boarder = User::factory()->boarder()->create();
    $people = ['owner' => $this->owner, 'manager' => $this->manager, 'collector' => $this->collector, 'other_owner' => $other, 'boarder' => $boarder];
    $p = $this->property->id;

    expect(codes($people, 'GET', "/api/v1/properties/{$p}"))
        ->toBe(['owner' => 200, 'manager' => 200, 'collector' => 200, 'other_owner' => 404, 'boarder' => 404]);

    expect(codes($people, 'PATCH', "/api/v1/properties/{$p}", ['description' => 'Near UST']))
        ->toBe(['owner' => 200, 'manager' => 200, 'collector' => 403, 'other_owner' => 404, 'boarder' => 404]);

    expect(codes($people, 'PUT', "/api/v1/units/{$this->unit->id}/rent", ['amount_centavos' => 210000]))
        ->toBe(['owner' => 200, 'manager' => 200, 'collector' => 403, 'other_owner' => 404, 'boarder' => 404]);

    expect(codes($people, 'PUT', "/api/v1/properties/{$p}/settings", ['grace_days' => 2]))
        ->toBe(['owner' => 200, 'manager' => 200, 'collector' => 403, 'other_owner' => 404, 'boarder' => 404]);

    // Same assignments re-sent, so nobody loses access mid-test.
    expect(codes($people, 'PUT', "/api/v1/properties/{$p}/caretakers", ['assignments' => [
        ['caretaker_id' => $this->manager->id, 'access_level' => 'manager'],
        ['caretaker_id' => $this->collector->id, 'access_level' => 'collector'],
    ]]))->toBe(['owner' => 200, 'manager' => 403, 'collector' => 403, 'other_owner' => 404, 'boarder' => 404]);
});

it('never lets managers delete a property', function () {
    $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/v1/properties/{$this->property->id}")->assertForbidden();
    expect($this->property->fresh()->trashed())->toBeFalse();
});

it('lists only your own or your assigned properties', function () {
    $other = User::factory()->owner()->create();
    makeProperty($other, ['details' => ['name' => 'Not yours']]);
    $unassigned = makeProperty($this->owner, ['details' => ['name' => 'Second house']]);

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/properties')->assertJsonCount(2, 'data');
    $this->actingAs($other, 'sanctum')->getJson('/api/v1/properties')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Not yours');

    $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/properties')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->property->id)
        ->assertJsonPath('data.0.my_role', 'manager');

    $this->getJson("/api/v1/properties/{$unassigned->id}")->assertNotFound();
});

it('returns the viewer\'s abilities so the app shows only allowed actions', function () {
    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/properties/{$this->property->id}")
        ->assertJsonPath('data.my_role', 'collector')
        ->assertJsonPath('data.caretakers', null)
        ->assertJsonMissingPath('data.abilities.20');
    $abilities = $this->getJson("/api/v1/properties/{$this->property->id}")->json('data.abilities');
    expect($abilities)->toContain('record_payments')->not->toContain('manage_prices');
});

it('drops access as soon as the owner removes the caretaker', function () {
    $link = OwnerCaretaker::where('caretaker_id', $this->manager->id)->first();

    $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/owner/caretakers/{$link->id}")->assertOk();

    $this->actingAs($this->manager->fresh(), 'sanctum')->getJson("/api/v1/properties/{$this->property->id}")->assertNotFound();
    expect($this->property->caretakerAssignments()->where('caretaker_id', $this->manager->id)->exists())->toBeFalse();
});

it('assigns caretakers per property with a level, only from the owner\'s own caretakers', function () {
    $stranger = User::factory()->boarder()->create();

    $this->actingAs($this->owner, 'sanctum')
        ->putJson("/api/v1/properties/{$this->property->id}/caretakers", ['assignments' => [
            ['caretaker_id' => $stranger->id, 'access_level' => 'manager'],
        ]])->assertUnprocessable();

    $this->putJson("/api/v1/properties/{$this->property->id}/caretakers", ['assignments' => [
        ['caretaker_id' => $this->collector->id, 'access_level' => 'manager'],
    ]])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.access_level', 'manager');

    // The manager was left out: unassigned. The collector is now a manager here.
    $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/properties/{$this->property->id}")->assertNotFound();
    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/properties/{$this->property->id}")->assertJsonPath('data.my_role', 'manager');
});

it('assigns the properties picked on the invitation when it is accepted', function () {
    Notification::fake();
    $second = makeProperty($this->owner, ['details' => ['name' => 'Second house']]);

    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/owner/caretaker-invitations', [
        'email' => 'dana@example.com', 'access_level' => 'collector', 'property_ids' => [$second->id],
    ])->assertCreated();

    [$invitation] = [CaretakerInvitation::latest('id')->first()];
    $token = str()->random(48);
    $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();
    $dana = User::factory()->boarder()->create(['email' => 'dana@example.com']);

    $this->actingAs($dana, 'sanctum')->postJson("/api/v1/caretaker-invitations/{$token}/accept")->assertOk();

    $this->getJson('/api/v1/properties')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Second house');
});
