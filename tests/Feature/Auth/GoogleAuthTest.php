<?php

use App\Models\User;
use App\Services\SocialAuth\Contracts\GoogleTokenVerifier;
use App\Services\SocialAuth\GoogleIdentity;
use App\Services\SocialAuth\InvalidGoogleToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/** Makes the API accept "good-token" as this Google identity. */
function fakeGoogle(string $sub = 'g-123', string $email = 'kim@gmail.com', string $name = 'Kim Santos'): void
{
    app()->instance(GoogleTokenVerifier::class, new class($sub, $email, $name) implements GoogleTokenVerifier
    {
        public function __construct(private string $sub, private string $email, private string $name) {}

        public function verify(string $idToken): GoogleIdentity
        {
            if ($idToken !== 'good-token') {
                throw new InvalidGoogleToken('Google sign-in token is invalid or expired.');
            }

            return new GoogleIdentity($this->sub, $this->email, $this->name);
        }
    });
}

beforeEach(fn () => Notification::fake());

it('asks a new person for consent and creates nothing', function () {
    fakeGoogle();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'good-token'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'consent_required');

    expect(User::count())->toBe(0);
});

it('creates a verified boarder account after consent and logs in', function () {
    fakeGoogle();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'good-token', 'consent' => true])
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'kim@gmail.com')
        ->assertJsonPath('data.user.roles', ['boarder'])
        ->assertJsonPath('data.user.google_linked', true)
        ->assertJsonPath('data.user.has_password', false)
        ->assertJsonStructure(['data' => ['token']]);

    $user = User::first();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->consented_at)->not->toBeNull()
        ->and($user->google_id)->toBe('g-123');
});

it('logs in a linked account on later sign-ins', function () {
    fakeGoogle();
    $user = User::factory()->boarder()->create(['email' => 'kim@gmail.com']);
    $user->forceFill(['google_id' => 'g-123'])->save();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'good-token'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);
});

it('links an existing password account with the same email and verifies it', function () {
    fakeGoogle();
    $user = User::factory()->boarder()->unverified()->create(['email' => 'kim@gmail.com']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'good-token'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.has_password', true);

    $user->refresh();
    expect($user->google_id)->toBe('g-123')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(User::count())->toBe(1);
});

it('refuses an email already linked to a different Google account', function () {
    fakeGoogle(sub: 'g-new');
    $user = User::factory()->boarder()->create(['email' => 'kim@gmail.com']);
    $user->forceFill(['google_id' => 'g-old'])->save();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'good-token'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token']);
});

it('rejects invalid tokens and suspended accounts', function () {
    fakeGoogle();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'forged'])
        ->assertUnprocessable()->assertJsonValidationErrors(['id_token']);

    $user = User::factory()->boarder()->suspended()->create(['email' => 'kim@gmail.com']);
    $user->forceFill(['google_id' => 'g-123'])->save();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'good-token'])
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('lets a Google-only account set a first password without the current one', function () {
    $user = User::factory()->boarder()->create(['password' => null]);

    $token = $user->createToken('this')->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/me/password', [
        'password' => 'newsecret1',
        'password_confirmation' => 'newsecret1',
    ])->assertOk()->assertJsonPath('message', 'Password set. You can now also log in with your email and password.');

    expect(Hash::check('newsecret1', $user->fresh()->password))->toBeTrue();
});

it('does not let Google-only accounts log in with an empty password', function () {
    User::factory()->boarder()->create(['password' => null, 'email' => 'kim@gmail.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'kim@gmail.com', 'password' => ''])
        ->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => 'kim@gmail.com', 'password' => 'anything'])
        ->assertUnprocessable();
});
