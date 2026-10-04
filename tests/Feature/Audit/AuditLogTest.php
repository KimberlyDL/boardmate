<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\OwnerVerificationStatus;
use App\Models\CaretakerInvitation;
use App\Models\OwnerCaretaker;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->owner = User::factory()->owner()->create(['name' => 'Owner Ana']);
});

it('records an admin decision in the admin log and the owner\'s log, hiding the admin\'s name from the owner', function () {
    $pending = User::factory()->owner(OwnerVerificationStatus::Pending)->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/owners/{$pending->id}/reject", ['reason' => 'Please add the address.'])
        ->assertOk();

    $this->getJson('/api/v1/admin/audit-log')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'owner.rejected')
        ->assertJsonPath('data.0.actor.name', 'Ada Admin')
        ->assertJsonPath('data.0.reason', 'Please add the address.')
        ->assertJsonPath('data.0.changes.0.label', 'Status')
        ->assertJsonPath('data.0.changes.0.old', 'Under review')
        ->assertJsonPath('data.0.changes.0.new', 'Not approved');

    $this->actingAs($pending, 'sanctum')
        ->getJson('/api/v1/owner/audit-log')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'owner.rejected')
        ->assertJsonPath('data.0.actor.name', 'BoardMate team')
        ->assertJsonPath('data.0.actor.id', null);
});

it('records only changed payment fields, with account numbers masked', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/owner/profile', ['gcash_name' => 'Ana Santos', 'gcash_number' => '0917 123 4567'])
        ->assertOk();
    $this->putJson('/api/v1/owner/profile', ['gcash_name' => 'Ana Santos', 'gcash_number' => '0917 123 4567'])
        ->assertOk(); // no change → no entry

    $entries = Activity::where('event', 'owner.details_changed')->get();
    expect($entries)->toHaveCount(1);

    $changes = $entries->first()->properties['changes'];
    expect($changes['gcash_number']['new'])->toBe('•••• 4567')
        ->and(json_encode($entries->first()->properties))->not->toContain('1234567');

    $this->getJson('/api/v1/owner/audit-log')
        ->assertJsonPath('data.0.actor.acting_as', 'owner')
        ->assertJsonPath('data.0.actor.acting_as_label', 'Owner');
});

it('names the caretaker and their level on caretaker actions', function () {
    [$invitation, $token] = CaretakerInvitation::issue($this->owner, 'carla@example.com', CaretakerAccessLevel::Manager);
    $carla = User::factory()->boarder()->create(['name' => 'Carla Cruz', 'email' => 'carla@example.com']);

    $this->actingAs($carla, 'sanctum')->postJson("/api/v1/caretaker-invitations/{$token}/accept")->assertOk();

    $this->actingAs($this->owner, 'sanctum')
        ->getJson('/api/v1/owner/audit-log?event=caretaker.invitation_accepted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.actor.name', 'Carla Cruz')
        ->assertJsonPath('data.0.actor.acting_as_label', 'Caretaker (Manager)')
        ->assertJsonPath('data.0.note', 'Carla Cruz as Manager');
});

it('logs invites, access changes and removals in the owner\'s log', function () {
    $carla = User::factory()->caretakerFor($this->owner)->create(['name' => 'Carla Cruz']);
    $link = OwnerCaretaker::first();

    $this->actingAs($this->owner, 'sanctum');
    $this->postJson('/api/v1/owner/caretaker-invitations', ['email' => 'ben@example.com'])->assertCreated();
    $this->patchJson("/api/v1/owner/caretakers/{$link->id}", ['access_level' => 'manager'])->assertOk();
    $this->deleteJson("/api/v1/owner/caretakers/{$link->id}")->assertOk();

    $events = collect($this->getJson('/api/v1/owner/audit-log')->json('data'))->pluck('event')->all();
    expect($events)->toBe(['caretaker.removed', 'caretaker.access_changed', 'caretaker.invited']);

    $access = Activity::firstWhere('event', 'caretaker.access_changed');
    expect($access->properties['changes']['access_level'])->toBe(['old' => 'Collector', 'new' => 'Manager']);
});

it('keeps each owner\'s log private', function () {
    $ben = User::factory()->owner()->create();
    $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/owner/profile', ['business_name' => 'Ana House'])->assertOk();

    $this->actingAs($ben, 'sanctum')->getJson('/api/v1/owner/audit-log')->assertOk()->assertJsonCount(0, 'data');
});

it('lets only owners and admins read their logs', function () {
    $boarder = User::factory()->boarder()->create();
    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();

    $this->actingAs($boarder, 'sanctum')->getJson('/api/v1/owner/audit-log')->assertForbidden();
    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/owner/audit-log')->assertForbidden();
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/admin/audit-log')->assertForbidden();
});

it('keeps account security events out of owner and admin logs, and never stores passwords', function () {
    $token = $this->owner->createToken('phone')->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'password',
        'password' => 'newsecret1',
        'password_confirmation' => 'newsecret1',
    ])->assertOk();

    $entry = Activity::firstWhere('event', 'account.password_changed');
    expect($entry->log_name)->toBe('account')
        ->and($entry->owner_id)->toBeNull()
        ->and(json_encode($entry->toArray()))->not->toContain('newsecret1');

    $this->withToken($token)->getJson('/api/v1/owner/audit-log')->assertJsonCount(0, 'data');
});

it('logs account suspensions for admins', function () {
    $boarder = User::factory()->boarder()->create(['name' => 'Kim', 'email' => 'kim@example.com']);

    $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/users/{$boarder->id}/suspend")->assertOk();

    $this->getJson('/api/v1/admin/audit-log?event=account.suspended')
        ->assertJsonPath('data.0.note', 'Kim (kim@example.com)')
        ->assertJsonPath('data.0.actor.acting_as', 'admin');
});
