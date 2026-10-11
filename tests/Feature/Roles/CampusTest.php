<?php

use App\Models\Campus;
use App\Models\User;

beforeEach(fn () => $this->admin = User::factory()->admin()->create());

it('lets the admin add campuses; the first becomes the default', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/campuses', ['name' => 'Main Campus', 'latitude' => 14.6096, 'longitude' => 120.9895])
        ->assertCreated()
        ->assertJsonPath('data.0.name', 'Main Campus')
        ->assertJsonPath('data.0.is_default', true);

    $this->postJson('/api/v1/admin/campuses', ['name' => 'Annex', 'latitude' => 14.6, 'longitude' => 121.0])
        ->assertCreated()
        ->assertJsonPath('data.0.name', 'Main Campus')
        ->assertJsonPath('data.1.is_default', false);

    $this->postJson('/api/v1/admin/campuses', ['name' => 'Annex', 'latitude' => 14.6, 'longitude' => 121.0])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('moves the default, renames, and passes the default on when the default is removed', function () {
    $main = Campus::factory()->default()->create(['name' => 'Main']);
    $annex = Campus::factory()->create(['name' => 'Annex']);

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/admin/campuses/{$annex->id}", ['is_default' => true, 'name' => 'North Annex'])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'North Annex')
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.1.is_default', false);

    $this->deleteJson("/api/v1/admin/campuses/{$annex->id}")->assertOk()->assertJsonPath('data.0.id', $main->id)->assertJsonPath('data.0.is_default', true);
    expect(Campus::where('is_default', true)->count())->toBe(1);
});

it('shows the campuses to everyone, but only the admin can change them', function () {
    Campus::factory()->create(['name' => 'Annex']);
    Campus::factory()->default()->create(['name' => 'Main']);

    $this->getJson('/api/v1/campuses')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Main');

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')
        ->postJson('/api/v1/admin/campuses', ['name' => 'Mine', 'latitude' => 14.6, 'longitude' => 121.0])
        ->assertForbidden();
});
