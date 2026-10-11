<?php

use App\Enums\OwnerVerificationStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/** A published, findable property (verified owner, pin, rents). */
function listed(?User $owner = null, array $overrides = [], array $fill = []): Property
{
    $owner ??= User::factory()->owner()->create();
    $property = makeProperty($owner, $overrides + ['details' => []]);
    $property->forceFill(array_merge([
        'latitude' => 14.6091, 'longitude' => 120.9894, 'barangay' => 'Sampaloc', 'city' => 'Manila',
        'is_published' => true, 'published_at' => now(),
    ], $overrides['fill'] ?? [], $fill))->save();

    return $property;
}

beforeEach(fn () => Date::setTestNow(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Manila')));
afterEach(fn () => Date::setTestNow());

it('shows published places of verified, active owners, full ones too but after free ones', function () {
    $visible = listed();
    $draft = listed(overrides: ['fill' => ['is_published' => false]]);
    listed(User::factory()->owner(OwnerVerificationStatus::Pending)->create());
    listed(User::factory()->owner(OwnerVerificationStatus::Suspended)->create());
    $suspendedAccount = listed(User::factory()->owner()->suspended()->create());
    $full = listed(fill: ['published_at' => now()->addMinute()]);
    $full->units()->update(['status' => 'occupied']);
    $notReady = listed();
    $notReady->units()->update(['not_ready' => true]);
    $deleted = listed();
    $deleted->delete();

    $found = $this->getJson('/api/v1/listings')->assertOk()->json('data');
    expect(collect($found)->pluck('id')->first())->toBe($visible->id)
        ->and(collect($found)->pluck('id')->sort()->values()->all())->toBe(collect([$visible->id, $full->id, $notReady->id])->sort()->values()->all())
        ->and(collect($found)->firstWhere('id', $full->id)['is_full'])->toBeTrue()
        ->and($found[0]['is_full'])->toBeFalse();

    $this->getJson('/api/v1/listings?available=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    $this->getJson("/api/v1/listings/{$full->id}")->assertOk()->assertJsonPath('data.listing.is_full', true);

    foreach ([$draft, $suspendedAccount, $deleted] as $hidden) {
        $this->getJson("/api/v1/listings/{$hidden->id}")->assertNotFound();
    }

    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')
        ->postJson("/api/v1/listings/{$full->id}/applications", ['planned_move_in_on' => '2026-10-20', 'contact_phone' => '0917 123 4567'])
        ->assertUnprocessable()->assertJsonValidationErrors(['property']);
});

it('measures the straight-line distance from a point and sorts by nearest', function () {
    $far = listed(fill: ['latitude' => 14.6500, 'longitude' => 121.0300]);
    $near = listed(fill: ['latitude' => 14.6100, 'longitude' => 120.9900]);

    $found = $this->getJson('/api/v1/listings?near=14.6096,120.9895&sort=nearest')->assertOk()->json('data');

    expect(collect($found)->pluck('id')->all())->toBe([$near->id, $far->id])
        ->and($found[0]['distance_m'])->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($found[1]['distance_m'])->toBeGreaterThan(5000)->toBeLessThan(7000);

    $this->getJson('/api/v1/listings?sort=nearest')->assertUnprocessable()->assertJsonValidationErrors(['near']);
    expect($this->getJson('/api/v1/listings')->json('data.0.distance_m'))->toBeNull();
});

it('filters by who can stay, counting rooms for anyone', function () {
    $female = listed();
    $female->rooms()->update(['who_can_stay' => 'female']);
    $male = listed();
    $male->rooms()->update(['who_can_stay' => 'male']);
    $anyone = listed();

    $ids = fn (string $who) => collect($this->getJson("/api/v1/listings?who_can_stay={$who}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('female'))->toBe(collect([$female->id, $anyone->id])->sort()->values()->all())
        ->and($ids('male'))->toBe(collect([$male->id, $anyone->id])->sort()->values()->all());
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

it('lists every room with its status, price and free places, but never who lives there', function () {
    $property = listed(overrides: ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $beds = $property->rooms()->first();
    $beds->update(['floor' => 2, 'door_no' => '201', 'who_can_stay' => 'female']);
    $tenant = User::factory()->boarder()->create(['name' => 'Kim Santos']);
    $taken = $beds->units()->first();
    $taken->forceFill(['status' => 'occupied'])->save();
    Tenancy::factory()->forUnit($taken)->create(['tenant_id' => $tenant->id]);

    $this->actingAs($property->owner, 'sanctum');
    $whole = $this->postJson("/api/v1/properties/{$property->id}/rooms", [
        'rental_mode' => 'whole', 'floor' => 3, 'units' => ['capacity' => 3, 'rent_centavos' => 600000],
    ])->json('data.id');
    Room::find($whole)->units()->update(['status' => 'occupied']);
    $notReady = $this->postJson("/api/v1/properties/{$property->id}/rooms", [
        'rental_mode' => 'whole', 'units' => ['capacity' => 1, 'rent_centavos' => 300000],
    ])->json('data.id');
    Room::find($notReady)->units()->update(['not_ready' => true]);

    $rooms = collect($this->getJson("/api/v1/listings/{$property->id}")->assertOk()->json('data.listing.rooms'))->keyBy('id');

    expect($rooms[$beds->id])->toMatchArray([
        'label' => '201', 'floor' => 2, 'status' => 'available', 'who_can_stay_label' => 'Female only',
        'available' => 1, 'total' => 2, 'rent_from_centavos' => 200000, 'availability_label' => '1 of 2 bedspaces available',
    ])
        ->and($rooms[$whole])->toMatchArray(['status' => 'full', 'capacity' => 3, 'rent_from_centavos' => 600000, 'availability_label' => 'Whole room for up to 3'])
        ->and($rooms[$notReady]['status'])->toBe('not_ready')
        ->and(json_encode($rooms))->not->toContain('Kim Santos');

    // The owner's own rooms show who lives where.
    $units = collect($this->getJson("/api/v1/properties/{$property->id}")->assertOk()->json('data.rooms'))->firstWhere('id', $beds->id)['units'];
    expect(collect($units)->pluck('tenant_name')->all())->toBe(['Kim Santos', null]);
});
