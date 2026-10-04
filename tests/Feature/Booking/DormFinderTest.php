<?php

use App\Enums\OwnerVerificationStatus;
use App\Models\Property;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/** A published, findable property (verified owner, pin, rents). */
function listed(?User $owner = null, array $overrides = []): Property
{
    $owner ??= User::factory()->owner()->create();
    $property = makeProperty($owner, $overrides + ['details' => []]);
    $property->forceFill(array_merge([
        'latitude' => 14.6091, 'longitude' => 120.9894, 'barangay' => 'Sampaloc', 'city' => 'Manila',
        'is_published' => true, 'published_at' => now(),
    ], $overrides['fill'] ?? []))->save();

    return $property;
}

beforeEach(fn () => Date::setTestNow(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Manila')));
afterEach(fn () => Date::setTestNow());

it('shows only published listings of verified, active owners with a free unit', function () {
    $visible = listed();
    $draft = listed(overrides: ['fill' => ['is_published' => false]]);
    listed(User::factory()->owner(OwnerVerificationStatus::Pending)->create());
    listed(User::factory()->owner(OwnerVerificationStatus::Suspended)->create());
    $suspendedAccount = listed(User::factory()->owner()->suspended()->create());
    $full = listed();
    $full->units()->update(['status' => 'occupied']);
    $notReady = listed();
    $notReady->units()->update(['not_ready' => true]);
    $deleted = listed();
    $deleted->delete();

    $this->getJson('/api/v1/listings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id);

    foreach ([$draft, $suspendedAccount, $full, $notReady] as $hidden) {
        $this->getJson("/api/v1/listings/{$hidden->id}")->assertNotFound();
    }
});

it('shows price from, availability and included utilities, but nothing private', function () {
    $property = listed();
    app(PriceBook::class)->set($property->units()->first(), 150000, null, $property->owner);
    $property->utilityAccounts()->create(['type' => 'water', 'name' => 'Water', 'method' => 'included', 'billed_by' => 'owner']);
    $property->forceFill(['street' => '12 Dapitan St'])->save();

    $summary = $this->getJson('/api/v1/listings')->assertOk()->json('data.0');
    expect($summary['price_from_centavos'])->toBe(150000)
        ->and($summary['availability']['label'])->toBe('3 of 3 bedspaces available')
        ->and($summary['included_utilities'])->toBe(['water']);

    $detail = $this->getJson("/api/v1/listings/{$property->id}")->assertOk()->json('data.listing');
    expect($detail['street'])->toBeNull()
        ->and($detail['slots'])->toHaveCount(3)
        ->and(json_encode($detail))->not->toContain($property->owner->email)
        ->and(json_encode($detail))->not->toContain('09171234567')
        ->and($detail)->not->toHaveKeys(['caretakers', 'abilities', 'settings']);
});

it('filters by text, price, mode, inclusions, who-can-apply and map area, and sorts by price', function () {
    $cheap = listed(overrides: ['fill' => ['city' => 'Quezon City', 'who_can_apply' => 'Female only']]);
    app(PriceBook::class)->set($cheap->units()->first(), 120000, null, $cheap->owner);
    $whole = listed(overrides: ['rental_mode' => 'whole', 'fill' => ['latitude' => 10.3157, 'longitude' => 123.8854, 'city' => 'Cebu City']]);
    $whole->utilityAccounts()->create(['type' => 'internet', 'name' => 'Internet', 'method' => 'included', 'billed_by' => 'owner']);

    $ids = fn (string $query) => collect($this->getJson('/api/v1/listings?'.$query)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('q=quezon'))->toBe([$cheap->id])
        ->and($ids('max_price=130000'))->toBe([$cheap->id])
        ->and($ids('min_price=1000000'))->toBe([$whole->id])
        ->and($ids('rental_mode=whole'))->toBe([$whole->id])
        ->and($ids('includes[]=internet'))->toBe([$whole->id])
        ->and($ids('who=female'))->toBe([$cheap->id])
        ->and($ids('bbox=14,120,15,122'))->toBe([$cheap->id])
        ->and($ids('sort=price'))->toBe([$cheap->id, $whole->id]);
});

it('ignores scheduled future prices in "price from"', function () {
    $property = listed(overrides: ['units' => ['count' => 1, 'rent_centavos' => 200000]]);
    app(PriceBook::class)->set($property->units()->first(), 100000, '2026-12-01', $property->owner);

    expect($this->getJson('/api/v1/listings')->json('data.0.price_from_centavos'))->toBe(200000);
});
