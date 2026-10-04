<?php

use App\Enums\OwnerVerificationStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
});

it('starts every property with the guides\' default settings', function () {
    $values = $this->actingAs($this->owner, 'sanctum')
        ->getJson("/api/v1/properties/{$this->property->id}/settings")->assertOk()->json('data.values');

    expect($values)->toMatchArray([
        'due_date_policy' => 'anniversary',          // S5
        'activation_required' => true,               // SC-33
        'deposit_rule' => 'one_month_rent',          // F5
        'price_change_notice_days' => 30,            // F5
        'overstay_daily_centavos' => null,           // SC-29: rent ÷ 30
        'minimum_notice_days' => null,               // S8
        'suggested_notice_days' => 7,                // S8
        'short_notice_consequence' => 'none',
        'short_notice_fee_centavos' => 0,            // S8: ₱0
        'split_manager' => 'owner',                  // S3
        'split_method' => 'occupant_days',           // F5
        'utility_due_rule' => 'days_after_issue',
        'utility_due_days' => 7,                     // SC-20 N = 7
        'grace_days' => 3,                           // S8 timings
        'remind_before_days' => 3,
        'remind_on_due' => true,
        'overdue_reminder_days' => [1, 3],
        'mark_late_day' => 4,
        'late_fee_type' => 'off',
        'arrears_enabled' => false,
        'arrears_days' => 15,
        'notice_to_vacate_min_days' => 30,
        'notice_to_vacate_end_days' => 15,
        'early_warning_late_count' => 2,
        'early_warning_window_periods' => 6,
        'payment_promise_max_days' => 14,
        'correction_credit_handling' => 'roll_forward', // SC-14
        'reservation_expiry_days' => 7,              // F2
        'curfew_time' => null,                       // F9
        'curfew_reminder_minutes' => 30,
        'overnight_requires_approval' => true,       // F11
        'overnight_limit_per_month' => 3,
        'extra_occupant_fee_enabled' => false,
    ]);
});

it('saves settings, checks combinations, logs before → after, and resets a section', function () {
    $this->actingAs($this->owner, 'sanctum');
    $url = "/api/v1/properties/{$this->property->id}/settings";

    $this->putJson($url, ['due_date_policy' => 'common'])->assertUnprocessable()->assertJsonValidationErrors(['common_due_day']);
    $this->putJson($url, ['late_fee_type' => 'percentage'])->assertUnprocessable()->assertJsonValidationErrors(['late_fee_basis_points']);
    $this->putJson($url, ['grace_days' => 5])->assertUnprocessable()->assertJsonValidationErrors(['mark_late_day']);

    $this->putJson($url, ['due_date_policy' => 'common', 'common_due_day' => 5, 'curfew_time' => '22:00', 'grace_days' => 2])
        ->assertOk()
        ->assertJsonPath('data.values.common_due_day', 5)
        ->assertJsonPath('data.values.curfew_time', '22:00');

    $this->getJson('/api/v1/owner/audit-log?event=property.settings_changed')->assertJsonPath('data.0.changes.0.field', 'due_date_policy');

    $this->postJson("{$url}/reset", ['sections' => ['rent', 'curfew']])
        ->assertOk()
        ->assertJsonPath('data.values.due_date_policy', 'anniversary')
        ->assertJsonPath('data.values.curfew_time', null)
        ->assertJsonPath('data.values.grace_days', 2); // payments section untouched
});

it('publishes only when every requirement is met', function () {
    $this->actingAs($this->owner, 'sanctum');
    $url = "/api/v1/properties/{$this->property->id}";

    $missing = $this->postJson("{$url}/publish")->assertUnprocessable()->json('checklist');
    expect(collect($missing)->where('ok', false)->pluck('key')->all())->toBe(['location', 'photo']);

    $this->patchJson($url, ['latitude' => 14.6091, 'longitude' => 120.9894])->assertOk();
    $this->postJson("{$url}/photos", ['photos' => [UploadedFile::fake()->image('front.jpg')]])->assertCreated();

    $this->postJson("{$url}/publish")->assertOk()->assertJsonPath('data.is_published', true);
    $this->postJson("{$url}/unpublish")->assertOk()->assertJsonPath('data.is_published', false);
});

it('never lets an unverified owner publish', function () {
    $pending = User::factory()->owner(OwnerVerificationStatus::Pending)->create();
    $property = makeProperty($pending, ['details' => ['latitude' => 14.6, 'longitude' => 121.0]]);

    $this->actingAs($pending, 'sanctum')
        ->postJson("/api/v1/properties/{$property->id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg')]]);

    $checklist = $this->postJson("/api/v1/properties/{$property->id}/publish")->assertUnprocessable()->json('checklist');
    expect(collect($checklist)->firstWhere('key', 'owner_verified')['ok'])->toBeFalse();
});

it('needs rent on every unit and one unit ready', function () {
    $this->property->update(['latitude' => 14.6, 'longitude' => 121.0]);
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg')]]);
    $this->postJson("/api/v1/properties/{$this->property->id}/units", ['count' => 1]); // no rent
    $this->property->units()->update(['not_ready' => true]);

    $missing = collect($this->postJson("/api/v1/properties/{$this->property->id}/publish")->json('checklist'))
        ->where('ok', false)->pluck('key')->all();

    expect($missing)->toBe(['rent', 'ready_unit']);
});

it('stores listing photos publicly, keeps one cover, reorders, and caps at 15', function () {
    $this->actingAs($this->owner, 'sanctum');
    $url = "/api/v1/properties/{$this->property->id}/photos";

    $photos = $this->postJson($url, ['photos' => [
        UploadedFile::fake()->image('1.jpg'), UploadedFile::fake()->image('2.png'), UploadedFile::fake()->image('3.webp'),
    ]])->assertCreated()->json('data.photos');

    expect(collect($photos)->where('is_cover', true))->toHaveCount(1)
        ->and($photos[0]['url'])->toContain('/storage/listing-photos/');
    Storage::disk('public')->assertExists(str($photos[0]['url'])->after('/storage/')->toString());

    $ids = array_column($photos, 'id');
    $this->patchJson("{$url}/order", ['ids' => array_reverse($ids)])->assertOk()->assertJsonPath('data.photos.0.id', $ids[2]);
    $this->patchJson("{$url}/{$ids[1]}/cover")->assertOk();
    $this->deleteJson("{$url}/{$ids[1]}")->assertOk();
    expect($this->property->photos()->where('is_cover', true)->count())->toBe(1);

    $many = array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 14));
    $this->postJson($url, ['photos' => $many])->assertUnprocessable()->assertJsonValidationErrors(['photos']);
});

it('unpublishes the listing when its last photo is removed', function () {
    $this->property->update(['latitude' => 14.6, 'longitude' => 121.0]);
    $this->actingAs($this->owner, 'sanctum');
    $photo = $this->postJson("/api/v1/properties/{$this->property->id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg')]])
        ->json('data.photos.0.id');
    $this->postJson("/api/v1/properties/{$this->property->id}/publish")->assertOk();

    $this->deleteJson("/api/v1/properties/{$this->property->id}/photos/{$photo}")
        ->assertOk()->assertJsonPath('data.is_published', false);
});
