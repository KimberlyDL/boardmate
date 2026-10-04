<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\OwnerVerificationStatus;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function application(array $overrides = []): array
{
    return array_merge([
        'business_name' => 'Santos Boarding House',
        'application_notes' => 'Two-storey house in Sampaloc with 8 bedspaces, near UST.',
    ], $overrides);
}

it('makes a boarder a pending owner and tells the admins', function () {
    $admin = User::factory()->admin()->create();
    $boarder = User::factory()->boarder()->create(['phone' => null]);

    $this->actingAs($boarder, 'sanctum')
        ->postJson('/api/v1/owner/application', application(['phone' => '0917 555 1234']))
        ->assertOk()
        ->assertJsonPath('data.roles', ['boarder', 'owner'])
        ->assertJsonPath('data.active_role', 'owner')
        ->assertJsonPath('data.owner_profile.verification_status', 'pending')
        ->assertJsonPath('data.phone', '0917 555 1234');

    Notification::assertSentTo($admin, BoardMateNotification::class,
        fn (BoardMateNotification $n) => $n->event === NotificationEvent::OwnerApplicationSubmitted);
});

it('requires notes, and a phone when the account has none', function () {
    $boarder = User::factory()->boarder()->create(['phone' => null]);

    $this->actingAs($boarder, 'sanctum')
        ->postJson('/api/v1/owner/application', ['application_notes' => 'short'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['application_notes', 'phone']);
});

it('refuses a second application while pending or verified', function (OwnerVerificationStatus $status) {
    $owner = User::factory()->owner($status)->create();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/owner/application', application())
        ->assertStatus(409)
        ->assertJsonPath('code', 'owner_application_exists');
})->with([OwnerVerificationStatus::Pending, OwnerVerificationStatus::Verified, OwnerVerificationStatus::Suspended]);

it('lets a rejected owner apply again', function () {
    $owner = User::factory()->owner(OwnerVerificationStatus::Rejected)->create();
    $owner->ownerProfile->forceFill(['review_reason' => 'Add the address'])->save();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/owner/application', application())
        ->assertOk()
        ->assertJsonPath('data.owner_profile.verification_status', 'pending')
        ->assertJsonPath('data.owner_profile.review_reason', null);
});

it('lets only the owner update payment instructions', function () {
    $owner = User::factory()->owner()->create();
    $manager = User::factory()->caretakerFor($owner, CaretakerAccessLevel::Manager)->create();
    $payload = ['gcash_name' => 'Kim Santos', 'gcash_number' => '0917 123 4567'];

    $this->actingAs($owner, 'sanctum')->putJson('/api/v1/owner/profile', $payload)
        ->assertOk()
        ->assertJsonPath('data.owner_profile.payment.gcash_number', '0917 123 4567');

    $this->actingAs($manager, 'sanctum')->putJson('/api/v1/owner/profile', $payload)->assertForbidden();
});
