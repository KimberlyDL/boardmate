<?php

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function verificationUrl(User $user, ?string $email = null): string
{
    return URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($email ?? $user->email),
    ]);
}

it('verifies the email from the signed link and redirects to the app', function () {
    $user = User::factory()->unverified()->create();

    $this->get(verificationUrl($user))
        ->assertRedirect(config('app.frontend_url').'/email-verified?status=success');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('reports an already verified email', function () {
    $user = User::factory()->create();

    $this->get(verificationUrl($user))
        ->assertRedirect(config('app.frontend_url').'/email-verified?status=already');
});

it('rejects tampered or expired links', function () {
    $user = User::factory()->unverified()->create();

    $this->get(verificationUrl($user, 'someone-else@example.com'))
        ->assertRedirect(config('app.frontend_url').'/email-verified?status=invalid');

    $expired = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->subMinute(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);
    $this->get($expired)->assertRedirect(config('app.frontend_url').'/email-verified?status=invalid');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('blocks login until the email is confirmed', function () {
    User::factory()->unverified()->create(['email' => 'kim@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'kim@example.com', 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('code', 'email_unverified')
        ->assertJsonMissingPath('data.token');
});

it('blocks API access for an unverified account that still holds a token', function () {
    $user = User::factory()->unverified()->create();
    $token = $user->createToken('old')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'email_unverified');
});

it('resends the confirmation email without logging in', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'kim@example.com']);

    $this->postJson('/api/v1/auth/email/resend', ['email' => 'KIM@example.com'])->assertOk();

    Notification::assertSentTo($user, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::VerifyEmail);
});

it('answers the same for unknown or already verified emails, and sends nothing', function () {
    Notification::fake();
    User::factory()->create(['email' => 'verified@example.com']);

    $unknown = $this->postJson('/api/v1/auth/email/resend', ['email' => 'nobody@example.com'])->assertOk()->json('message');
    $verified = $this->postJson('/api/v1/auth/email/resend', ['email' => 'verified@example.com'])->assertOk()->json('message');

    expect($unknown)->toBe($verified);
    Notification::assertNothingSent();
});
