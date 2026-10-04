<?php

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function emailChangeUrl(User $user, ?string $email = null, int $minutes = 60): string
{
    return URL::temporarySignedRoute('api.v1.auth.email-change.verify', now()->addMinutes($minutes), [
        'id' => $user->id,
        'hash' => sha1($email ?? $user->pending_email),
    ]);
}

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->boarder()->create(['email' => 'kim@example.com']);
});

it('keeps the old email, mails a link to the new one and a notice to the old one', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/me/email', ['email' => 'Kim.New@Gmail.com', 'current_password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'kim@example.com')
        ->assertJsonPath('data.pending_email', 'kim.new@gmail.com');

    Notification::assertSentTo(new AnonymousNotifiable, BoardMateNotification::class,
        function (BoardMateNotification $n, array $channels, AnonymousNotifiable $notifiable) {
            return $n->event === NotificationEvent::EmailChangeConfirm
                && $notifiable->routes['mail'] === 'kim.new@gmail.com'
                && str_contains($n->data['url'], '/api/v1/auth/email-change/verify/');
        });

    Notification::assertSentTo($this->user, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::EmailChangeNotice
            && $n->data['masked_email'] === 'k***@gmail.com');
});

it('requires the current password and a free, different email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'current_password' => 'wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

    $this->postJson('/api/v1/me/email', ['email' => 'taken@example.com', 'current_password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);

    $this->postJson('/api/v1/me/email', ['email' => 'kim@example.com', 'current_password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('swaps the email when the link is opened', function () {
    $this->user->forceFill(['pending_email' => 'kim.new@gmail.com', 'pending_email_requested_at' => now()])->save();

    $this->get(emailChangeUrl($this->user))
        ->assertRedirect(config('app.frontend_url').'/email-verified?status=changed');

    $fresh = $this->user->fresh();
    expect($fresh->email)->toBe('kim.new@gmail.com')
        ->and($fresh->pending_email)->toBeNull()
        ->and($fresh->hasVerifiedEmail())->toBeTrue();

    $this->postJson('/api/v1/auth/login', ['email' => 'kim.new@gmail.com', 'password' => 'password'])->assertOk();
});

it('rejects an old link after the request was changed or cancelled', function () {
    $this->user->forceFill(['pending_email' => 'first@example.com'])->save();
    $oldLink = emailChangeUrl($this->user);

    $this->user->forceFill(['pending_email' => 'second@example.com'])->save();
    $this->get($oldLink)->assertRedirect(config('app.frontend_url').'/email-verified?status=invalid');

    $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/me/email')->assertOk()->assertJsonPath('data.pending_email', null);
    $this->get(emailChangeUrl($this->user, 'second@example.com'))
        ->assertRedirect(config('app.frontend_url').'/email-verified?status=invalid');

    expect($this->user->fresh()->email)->toBe('kim@example.com');
});

it('refuses the swap if someone else took the address meanwhile', function () {
    $this->user->forceFill(['pending_email' => 'race@example.com'])->save();
    User::factory()->create(['email' => 'race@example.com']);

    $this->get(emailChangeUrl($this->user))
        ->assertRedirect(config('app.frontend_url').'/email-verified?status=taken');

    expect($this->user->fresh()->email)->toBe('kim@example.com');
});

it('does not need a password for Google-only accounts', function () {
    $google = User::factory()->boarder()->create(['password' => null, 'email' => 'g@example.com']);

    $this->actingAs($google, 'sanctum')
        ->postJson('/api/v1/me/email', ['email' => 'g2@example.com'])
        ->assertOk();
});
