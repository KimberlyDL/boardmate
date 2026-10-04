<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->boarder()->create();
});

it('keeps a token alive while it is used within 30 days', function () {
    $plain = $this->user->createToken('app')->plainTextToken;
    PersonalAccessToken::query()->update(['created_at' => now()->subDays(90), 'last_used_at' => now()->subDays(29)]);

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    expect(PersonalAccessToken::first()->last_used_at->isToday())->toBeTrue();
});

it('expires a token unused for more than 30 days', function () {
    $plain = $this->user->createToken('app')->plainTextToken;
    PersonalAccessToken::query()->update(['created_at' => now()->subDays(40), 'last_used_at' => now()->subDays(31)]);

    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('expires a never-used token 30 days after it was created', function () {
    $plain = $this->user->createToken('app')->plainTextToken;
    PersonalAccessToken::query()->update(['created_at' => now()->subDays(31), 'last_used_at' => null]);

    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('prunes only expired tokens', function () {
    $this->user->createToken('fresh');
    $this->user->createToken('stale');
    PersonalAccessToken::where('name', 'stale')->update(['last_used_at' => now()->subDays(45)]);

    $this->artisan('boardmate:daily')->assertSuccessful();

    expect(PersonalAccessToken::pluck('name')->all())->toBe(['fresh']);
});
