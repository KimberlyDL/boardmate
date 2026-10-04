<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\OwnerVerificationStatus;
use App\Models\OwnerDocument;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
});

function application(array $overrides = []): array
{
    return array_merge([
        'business_name' => 'Santos Boarding House',
        'application_notes' => 'Two-storey house in Sampaloc with 8 bedspaces, near UST.',
        'phone' => '0917 555 0000',
        'documents' => [
            ['kind' => 'government_id', 'file' => UploadedFile::fake()->image('id.jpg')],
            ['kind' => 'property_proof', 'file' => UploadedFile::fake()->create('title.pdf', 300, 'application/pdf')],
        ],
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

it('requires a valid ID and a proof of the property', function () {
    $boarder = User::factory()->boarder()->create();

    $this->actingAs($boarder, 'sanctum')
        ->postJson('/api/v1/owner/application', application(['documents' => [
            ['kind' => 'business_permit', 'file' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf')],
        ]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['documents'])
        ->assertJsonFragment(['documents' => ['Attach: Valid government ID; Proof of the property (title, lease, or a utility bill in your name).']]);

    expect($boarder->fresh()->ownerProfile)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('refuses more than 5 files and files that are not images or PDFs', function () {
    $boarder = User::factory()->boarder()->create();
    $six = array_fill(0, 6, ['kind' => 'other', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]);

    $this->actingAs($boarder, 'sanctum')->postJson('/api/v1/owner/application', application(['documents' => $six]))
        ->assertJsonValidationErrors(['documents']);
    $this->postJson('/api/v1/owner/application', application(['documents' => [
        ['kind' => 'government_id', 'file' => UploadedFile::fake()->create('id.docx', 10)],
    ]]))->assertJsonValidationErrors(['documents.0.file']);
});

it('keeps the documents private and shows them to the admin by signed link', function () {
    $boarder = User::factory()->boarder()->create();
    $this->actingAs($boarder, 'sanctum')->postJson('/api/v1/owner/application', application())
        ->assertOk()
        ->assertJsonCount(2, 'data.owner_profile.documents');

    $admin = User::factory()->admin()->create();
    $documents = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/owners')
        ->assertJsonPath('data.0.owner_profile.documents.0.kind', 'government_id')
        ->assertJsonPath('data.0.owner_profile.documents.1.original_name', 'title.pdf')
        ->json('data.0.owner_profile.documents');

    expect($documents[0]['url'])->toContain('signature=');
    $this->get($documents[1]['url'])->assertOk();
    $this->get(preg_replace('/signature=\w+/', 'signature=forged', $documents[1]['url']))->assertForbidden();
});

it('lets a rejected owner replace documents when applying again', function () {
    $boarder = User::factory()->boarder()->create();
    $this->actingAs($boarder, 'sanctum')->postJson('/api/v1/owner/application', application());
    $boarder->ownerProfile->forceFill(['verification_status' => 'rejected', 'reviewed_at' => now()])->save();
    $oldId = OwnerDocument::where('kind', 'government_id')->first();

    // Removing the ID without a new one is refused.
    $this->postJson('/api/v1/owner/application', application(['documents' => [], 'remove_document_ids' => [$oldId->id]]))
        ->assertJsonValidationErrors(['documents']);

    $this->postJson('/api/v1/owner/application', application([
        'documents' => [['kind' => 'government_id', 'file' => UploadedFile::fake()->image('new-id.png')]],
        'remove_document_ids' => [$oldId->id],
    ]))->assertOk()
        ->assertJsonCount(2, 'data.owner_profile.documents')
        ->assertJsonPath('data.owner_profile.documents.1.original_name', 'new-id.png');

    Storage::disk('local')->assertMissing($oldId->path);
});

it('deletes the files 90 days after the decision but keeps the record', function () {
    $boarder = User::factory()->boarder()->create();
    $this->actingAs($boarder, 'sanctum')->postJson('/api/v1/owner/application', application());
    $boarder->ownerProfile->forceFill(['verification_status' => 'verified', 'reviewed_at' => CarbonImmutable::parse('2026-07-01 10:00', 'Asia/Manila')])->save();
    $pending = User::factory()->boarder()->create();
    $this->actingAs($pending, 'sanctum')->postJson('/api/v1/owner/application', application());
    $paths = OwnerDocument::pluck('path');

    Date::setTestNow(CarbonImmutable::parse('2026-09-28 09:00', 'Asia/Manila'));   // 89 days later
    $this->artisan('boardmate:daily')->assertSuccessful();
    expect(OwnerDocument::whereNull('purged_at')->count())->toBe(4);

    Date::setTestNow(CarbonImmutable::parse('2026-09-30 09:00', 'Asia/Manila'));
    $this->artisan('boardmate:daily')->assertSuccessful();
    Date::setTestNow();

    $decided = $boarder->ownerProfile->documents()->get();
    expect($decided->every(fn ($d) => $d->path === null && $d->purged_at !== null))->toBeTrue()
        ->and($pending->ownerProfile->documents()->whereNull('purged_at')->count())->toBe(2); // still under review
    Storage::disk('local')->assertMissing($paths[0]);
});

it('logs a first application against the new owner profile', function () {
    $boarder = User::factory()->boarder()->create();
    $this->actingAs($boarder, 'sanctum')->postJson('/api/v1/owner/application', application())->assertOk();

    $entry = Activity::where('event', 'owner.applied')->sole();
    expect($entry->subject_id)->toBe($boarder->fresh()->ownerProfile->id)
        ->and($entry->owner_id)->toBe($boarder->id);
});

it('does not apply twice when the form is sent twice', function () {
    $boarder = User::factory()->boarder()->create();
    $this->actingAs($boarder, 'sanctum')->postJson('/api/v1/owner/application', application())->assertOk();
    $this->postJson('/api/v1/owner/application', application())->assertStatus(409);

    expect(OwnerDocument::count())->toBe(2);
});
