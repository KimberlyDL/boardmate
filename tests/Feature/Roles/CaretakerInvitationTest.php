<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\OwnerVerificationStatus;
use App\Models\CaretakerInvitation;
use App\Models\OwnerCaretaker;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->owner = User::factory()->owner()->create(['name' => 'Owner Ana']);
});

/** Invite through the API and return the emailed token. */
function inviteCaretaker(User $owner, string $email, string $level = 'collector'): string
{
    test()->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/owner/caretaker-invitations', ['email' => $email, 'access_level' => $level])
        ->assertCreated();

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, BoardMateNotification::class,
        function (BoardMateNotification $n, $channels, AnonymousNotifiable $to) use ($email, &$token) {
            if ($n->event !== NotificationEvent::CaretakerInvitation || $to->routes['mail'] !== $email) {
                return false;
            }
            $token = basename($n->data['url']);

            return true;
        });

    return $token;
}

it('emails an invitation that opens in the app, storing only a hash of the token', function () {
    $token = inviteCaretaker($this->owner, 'carla@example.com', 'manager');

    $invitation = CaretakerInvitation::first();
    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->access_level)->toBe(CaretakerAccessLevel::Manager);

    $this->getJson("/api/v1/caretaker-invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.owner_name', 'Owner Ana')
        ->assertJsonPath('data.email_hint', 'c***@example.com')
        ->assertJsonPath('data.status', 'pending');
});

it('lets the invited person accept and become a caretaker', function () {
    $token = inviteCaretaker($this->owner, 'carla@example.com');
    $carla = User::factory()->boarder()->create(['email' => 'carla@example.com']);

    $this->actingAs($carla, 'sanctum')
        ->postJson("/api/v1/caretaker-invitations/{$token}/accept")
        ->assertOk()
        ->assertJsonPath('data.roles', ['boarder', 'caretaker'])
        ->assertJsonPath('data.active_role', 'caretaker');

    $this->getJson('/api/v1/caretaker/owners')
        ->assertOk()
        ->assertJsonPath('data.0.person.name', 'Owner Ana')
        ->assertJsonPath('data.0.access_level', 'collector');

    Notification::assertSentTo($this->owner, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::CaretakerInvitationAccepted);

    $this->postJson("/api/v1/caretaker-invitations/{$token}/accept")
        ->assertStatus(410)->assertJsonPath('code', 'invitation_accepted');
});

it('refuses someone signed in with a different email', function () {
    $token = inviteCaretaker($this->owner, 'carla@example.com');
    $other = User::factory()->boarder()->create(['email' => 'someone@example.com']);

    $this->actingAs($other, 'sanctum')
        ->postJson("/api/v1/caretaker-invitations/{$token}/accept")
        ->assertForbidden()
        ->assertJsonPath('code', 'invitation_email_mismatch');

    expect(OwnerCaretaker::count())->toBe(0);
});

it('rejects expired, cancelled and replaced invitations', function () {
    $carla = User::factory()->boarder()->create(['email' => 'carla@example.com']);

    $first = inviteCaretaker($this->owner, 'carla@example.com');
    $second = inviteCaretaker($this->owner, 'carla@example.com'); // replaces the first

    $this->actingAs($carla, 'sanctum')->postJson("/api/v1/caretaker-invitations/{$first}/accept")
        ->assertStatus(410)->assertJsonPath('code', 'invitation_revoked');

    CaretakerInvitation::query()->update(['expires_at' => now()->subMinute()]);
    $this->postJson("/api/v1/caretaker-invitations/{$second}/accept")
        ->assertStatus(410)->assertJsonPath('code', 'invitation_expired');
});

it('lets the invited person decline', function () {
    $token = inviteCaretaker($this->owner, 'carla@example.com');
    $carla = User::factory()->boarder()->create(['email' => 'carla@example.com']);

    $this->actingAs($carla, 'sanctum')->postJson("/api/v1/caretaker-invitations/{$token}/decline")->assertOk();

    expect(CaretakerInvitation::first()->declined_at)->not->toBeNull()
        ->and($carla->fresh()->hasRole('caretaker'))->toBeFalse();
});

it('only lets verified owners invite, and not themselves or existing caretakers', function () {
    $pending = User::factory()->owner(OwnerVerificationStatus::Pending)->create();
    $this->actingAs($pending, 'sanctum')
        ->postJson('/api/v1/owner/caretaker-invitations', ['email' => 'carla@example.com'])
        ->assertForbidden()->assertJsonPath('code', 'owner_not_verified');

    $existing = User::factory()->caretakerFor($this->owner)->create();
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/owner/caretaker-invitations', ['email' => $existing->email])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);

    $this->postJson('/api/v1/owner/caretaker-invitations', ['email' => $this->owner->email])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('changes access level and removes a caretaker, dropping the role when no owners remain', function () {
    $carla = User::factory()->caretakerFor($this->owner)->create();
    $link = OwnerCaretaker::first();

    $this->actingAs($this->owner, 'sanctum')
        ->patchJson("/api/v1/owner/caretakers/{$link->id}", ['access_level' => 'manager'])
        ->assertOk()->assertJsonPath('data.access_level', 'manager');

    $this->deleteJson("/api/v1/owner/caretakers/{$link->id}")->assertOk();

    $carla->refresh();
    expect($carla->hasRole('caretaker'))->toBeFalse()
        ->and($carla->active_role)->toBe('boarder');
    Notification::assertSentTo($carla, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::CaretakerRemoved);
});

it('keeps the caretaker role while another owner still employs them', function () {
    $otherOwner = User::factory()->owner()->create();
    $carla = User::factory()->caretakerFor($this->owner)->create();
    OwnerCaretaker::create(['owner_id' => $otherOwner->id, 'caretaker_id' => $carla->id, 'access_level' => 'collector']);

    $link = OwnerCaretaker::where('owner_id', $this->owner->id)->first();
    $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/owner/caretakers/{$link->id}")->assertOk();

    expect($carla->fresh()->hasRole('caretaker'))->toBeTrue();
});
