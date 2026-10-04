<?php

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('emails a reset link that opens the app', function () {
    $user = User::factory()->create(['email' => 'kim@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'kim@example.com'])->assertOk();

    Notification::assertSentTo($user, BoardMateNotification::class, function (BoardMateNotification $n) {
        return $n->event === NotificationEvent::PasswordReset
            && str_starts_with($n->data['url'], config('app.frontend_url').'/reset-password?token=')
            && str_contains($n->data['url'], 'email=kim%40example.com');
    });
});

it('answers the same for unknown emails', function () {
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'If an account uses that email, we sent a link to reset the password.');

    Notification::assertNothingSent();
});

it('resets the password, verifies the email and signs out every device', function () {
    $user = User::factory()->unverified()->create(['email' => 'kim@example.com']);
    $user->createToken('old-phone');

    $token = null;
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'kim@example.com']);
    Notification::assertSentTo($user, BoardMateNotification::class, function (BoardMateNotification $n) use (&$token) {
        parse_str(parse_url($n->data['url'], PHP_URL_QUERY), $query);
        $token = $query['token'];

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'kim@example.com',
        'password' => 'newsecret1',
        'password_confirmation' => 'newsecret1',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('newsecret1', $user->password))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects an invalid reset token', function () {
    User::factory()->create(['email' => 'kim@example.com']);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'bogus',
        'email' => 'kim@example.com',
        'password' => 'newsecret1',
        'password_confirmation' => 'newsecret1',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});
