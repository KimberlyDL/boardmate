<?php

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;

function signUpPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kim Santos',
        'email' => 'Kim@Example.com',
        'phone' => '0917 123 4567',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'consent' => true,
    ], $overrides);
}

beforeEach(fn () => Notification::fake());

it('creates an unverified boarder account with consent and returns no token', function () {
    $this->postJson('/api/v1/auth/register', signUpPayload())
        ->assertCreated()
        ->assertJsonPath('data.email', 'kim@example.com')
        ->assertJsonMissingPath('data.token');

    $user = User::firstWhere('email', 'kim@example.com');
    expect($user->hasRole('boarder'))->toBeTrue()
        ->and($user->active_role)->toBe('boarder')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->consented_at)->not->toBeNull()
        ->and($user->privacy_policy_version)->toBe(config('boardmate.privacy_policy_version'))
        ->and($user->boarderProfile)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
});

it('sends the verification email and an in-app welcome', function () {
    $this->postJson('/api/v1/auth/register', signUpPayload())->assertCreated();

    $user = User::firstWhere('email', 'kim@example.com');

    Notification::assertSentTo($user, BoardMateNotification::class,
        fn (BoardMateNotification $n, array $channels) => $n->event === NotificationEvent::VerifyEmail && $channels === ['mail']);
    Notification::assertSentTo($user, BoardMateNotification::class,
        fn (BoardMateNotification $n, array $channels) => $n->event === NotificationEvent::Welcome && $channels === ['database']);
});

it('still creates the account when the mail server is down', function () {
    Notification::swap(new ChannelManager(app())); // real channels, not the fake
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]);

    $this->postJson('/api/v1/auth/register', signUpPayload())->assertCreated();

    $user = User::firstWhere('email', 'kim@example.com');
    expect($user)->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1); // the in-app welcome
});

it('requires consent to the privacy notice', function () {
    $this->postJson('/api/v1/auth/register', signUpPayload(['consent' => false]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['consent']);

    expect(User::count())->toBe(0);
});

it('rejects weak passwords, mismatched confirmation and duplicate emails', function () {
    User::factory()->create(['email' => 'kim@example.com']);

    $this->postJson('/api/v1/auth/register', signUpPayload([
        'password' => 'short',
        'password_confirmation' => 'other',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
});
