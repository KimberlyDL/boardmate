<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->user = User::factory()->boarder()->create();
});

it('updates name and phone', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson('/api/v1/me', ['name' => 'Kim S. Santos', 'phone' => '+63 917 123 4567'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Kim S. Santos')
        ->assertJsonPath('data.phone', '+63 917 123 4567');
});

it('saves the boarder emergency contact', function () {
    $this->actingAs($this->user, 'sanctum')
        ->putJson('/api/v1/me/boarder-profile', [
            'emergency_contact_name' => 'Ana Santos',
            'emergency_contact_phone' => '09181234567',
            'emergency_contact_relationship' => 'Mother',
        ])
        ->assertOk()
        ->assertJsonPath('data.boarder_profile.emergency_contact_name', 'Ana Santos');
});

it('requires a phone when an emergency contact name is given', function () {
    $this->actingAs($this->user, 'sanctum')
        ->putJson('/api/v1/me/boarder-profile', ['emergency_contact_name' => 'Ana Santos'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['emergency_contact_phone']);
});

it('stores the profile photo privately, replaces the old one, and serves it by signed link', function () {
    $first = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])
        ->assertOk();

    $oldPath = $this->user->fresh()->photo_path;
    Storage::disk('local')->assertExists($oldPath);
    Storage::disk('public')->assertMissing($oldPath);

    $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me2.png')])->assertOk();

    Storage::disk('local')->assertMissing($oldPath);

    $url = $this->getJson('/api/v1/me')->json('data.photo_url');
    expect($url)->toContain('/api/v1/files/')->toContain('signature=');

    $this->get($url)->assertOk();
    $this->get(strtok($url, '?'))->assertForbidden();
});

it('rejects photos that are too big or the wrong type', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertUnprocessable();

    $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('big.jpg')->size(6000)])
        ->assertUnprocessable();
});

it('changes the password and signs out other devices only', function () {
    $this->user->createToken('other-phone');
    $current = $this->user->createToken('this-phone')->plainTextToken;

    $this->withToken($current)
        ->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'newsecret1',
            'password_confirmation' => 'newsecret1',
        ])
        ->assertOk();

    expect(Hash::check('newsecret1', $this->user->fresh()->password))->toBeTrue()
        ->and($this->user->tokens()->pluck('name')->all())->toBe(['this-phone']);
});

it('rejects a wrong current password', function () {
    $this->actingAs($this->user, 'sanctum')
        ->putJson('/api/v1/me/password', [
            'current_password' => 'wrong',
            'password' => 'newsecret1',
            'password_confirmation' => 'newsecret1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});
