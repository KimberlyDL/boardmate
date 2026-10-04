<?php

use App\Models\User;

it('logs in, reads me, and logs out', function () {
    $user = User::factory()->boarder()->create(['email' => 'kim@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'KIM@example.com',
        'password' => 'password',
    ])->assertOk()->json('data.token');

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.roles', ['boarder'])
        ->assertJsonStructure(['data' => ['boarder_profile' => ['emergency_contact_name']]]);

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('rejects a wrong password with a field error', function () {
    User::factory()->create(['email' => 'kim@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'kim@example.com', 'password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('blocks suspended accounts at login and on existing tokens', function () {
    $user = User::factory()->boarder()->create(['email' => 'kim@example.com']);
    $token = $user->createToken('app')->plainTextToken;
    $user->forceFill(['suspended_at' => now()])->save();

    $this->postJson('/api/v1/auth/login', ['email' => 'kim@example.com', 'password' => 'password'])
        ->assertForbidden();

    $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
});

it('requires a token for account endpoints', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('rate limits repeated login attempts', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => 'kim@example.com', 'password' => 'x']);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'kim@example.com', 'password' => 'x'])
        ->assertTooManyRequests();
});
