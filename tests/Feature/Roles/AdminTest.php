<?php

use App\Enums\NotificationEvent;
use App\Enums\OwnerVerificationStatus as Status;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

it('lists pending owner applications first-come first-served, with counts', function () {
    $older = User::factory()->owner(Status::Pending)->create();
    $older->ownerProfile->forceFill(['submitted_at' => now()->subDays(2)])->save();
    $newer = User::factory()->owner(Status::Pending)->create();
    User::factory()->owner(Status::Verified)->create();

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/admin/owners')
        ->assertOk()
        ->assertJsonPath('data.0.id', $older->id)
        ->assertJsonPath('data.1.id', $newer->id)
        ->assertJsonPath('meta.counts.pending', 2)
        ->assertJsonPath('meta.counts.verified', 1);
});

it('verifies an owner and emails them', function () {
    $owner = User::factory()->owner(Status::Pending)->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/owners/{$owner->id}/verify")
        ->assertOk()
        ->assertJsonPath('data.owner_profile.verification_status', 'verified');

    expect($owner->fresh()->isVerifiedOwner())->toBeTrue()
        ->and($owner->ownerProfile->fresh()->reviewed_by)->toBe($this->admin->id);
    Notification::assertSentTo($owner, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::OwnerApplicationApproved);
});

it('rejects with a reason the owner sees', function () {
    $owner = User::factory()->owner(Status::Pending)->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/owners/{$owner->id}/reject", [])
        ->assertUnprocessable();

    $this->postJson("/api/v1/admin/owners/{$owner->id}/reject", ['reason' => 'Please add the property address.'])
        ->assertOk()
        ->assertJsonPath('data.owner_profile.review_reason', 'Please add the property address.');

    Notification::assertSentTo($owner, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::OwnerApplicationRejected
            && $n->data['reason'] === 'Please add the property address.');
});

it('suspends and reinstates an owner, and refuses impossible moves', function () {
    $owner = User::factory()->owner(Status::Verified)->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/owners/{$owner->id}/reject", ['reason' => 'Already verified owner'])
        ->assertStatus(409);

    $this->postJson("/api/v1/admin/owners/{$owner->id}/suspend", ['reason' => 'Fake listings reported'])
        ->assertOk()->assertJsonPath('data.owner_profile.verification_status', 'suspended');

    $this->postJson("/api/v1/admin/owners/{$owner->id}/reinstate")
        ->assertOk()->assertJsonPath('data.owner_profile.verification_status', 'verified');
});

it('suspends an account, signs it out, and cannot suspend admins', function () {
    $boarder = User::factory()->boarder()->create();
    $boarder->createToken('phone');
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$boarder->id}/suspend")
        ->assertOk();

    expect($boarder->fresh()->isSuspended())->toBeTrue()
        ->and($boarder->tokens()->count())->toBe(0);

    $this->postJson("/api/v1/admin/users/{$otherAdmin->id}/suspend")->assertForbidden();
    $this->postJson("/api/v1/admin/users/{$boarder->id}/unsuspend")->assertOk();
    expect($boarder->fresh()->isSuspended())->toBeFalse();
});

it('searches accounts by name or email and filters by role', function () {
    User::factory()->boarder()->create(['name' => 'Kim Santos', 'email' => 'kim@example.com']);
    User::factory()->owner()->create(['name' => 'Ben Reyes']);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/admin/users?search=KIM')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'kim@example.com');

    $this->getJson('/api/v1/admin/users?role=owner')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Ben Reyes');
});
