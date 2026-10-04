<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\CaretakerInvitation;
use App\Models\OwnerCaretaker;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
| Who can reach which area of the API, and owner data separation (Technical
| standards: no owner or caretaker can see another owner's data).
*/

beforeEach(fn () => Notification::fake());

it('keeps boarders out of owner, caretaker and admin areas', function (string $method, string $uri) {
    $boarder = User::factory()->boarder()->create();

    $this->actingAs($boarder, 'sanctum')->json($method, $uri)->assertForbidden();
})->with([
    ['GET', '/api/v1/owner/caretakers'],
    ['PUT', '/api/v1/owner/profile'],
    ['GET', '/api/v1/caretaker/owners'],
    ['GET', '/api/v1/admin/owners'],
    ['GET', '/api/v1/admin/users'],
]);

it('keeps owners and caretakers out of the admin area', function () {
    $owner = User::factory()->owner()->create();
    $manager = User::factory()->caretakerFor($owner, CaretakerAccessLevel::Manager)->create();

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/owners')->assertForbidden();
    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/users')->assertForbidden();
});

it('keeps manager caretakers out of caretaker management', function () {
    $owner = User::factory()->owner()->create();
    $manager = User::factory()->caretakerFor($owner, CaretakerAccessLevel::Manager)->create();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/owner/caretaker-invitations', ['email' => 'x@example.com'])
        ->assertForbidden();
});

it('stops one owner from touching another owner\'s caretakers or invitations', function () {
    $ana = User::factory()->owner()->create();
    $ben = User::factory()->owner()->create();
    User::factory()->caretakerFor($ana)->create();
    $anasLink = OwnerCaretaker::first();
    [$anasInvite] = CaretakerInvitation::issue($ana, 'carla@example.com', CaretakerAccessLevel::Collector);

    $this->actingAs($ben, 'sanctum');
    $this->getJson('/api/v1/owner/caretakers')->assertOk()->assertJsonCount(0, 'data.caretakers')->assertJsonCount(0, 'data.invitations');
    $this->patchJson("/api/v1/owner/caretakers/{$anasLink->id}", ['access_level' => 'manager'])->assertNotFound();
    $this->deleteJson("/api/v1/owner/caretakers/{$anasLink->id}")->assertNotFound();
    $this->deleteJson("/api/v1/owner/caretaker-invitations/{$anasInvite->id}")->assertNotFound();

    expect($anasLink->fresh()->removed_at)->toBeNull()
        ->and($anasInvite->fresh()->revoked_at)->toBeNull();
});

it('switches only to roles the account holds', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/me/active-role', ['role' => 'boarder'])
        ->assertOk()->assertJsonPath('data.active_role', 'boarder');

    $this->patchJson('/api/v1/me/active-role', ['role' => 'platform_admin'])
        ->assertUnprocessable()->assertJsonValidationErrors(['role']);
});
