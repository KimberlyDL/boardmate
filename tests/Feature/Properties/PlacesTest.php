<?php

use App\Models\City;
use App\Models\Province;
use App\Models\User;
use App\Services\Places\PlaceImporter;
use App\Services\Places\PlaceMatcher;

it('lists every province with its cities for the location pickers, without signing in', function () {
    $provinces = collect($this->getJson('/api/v1/places')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=86400, public')
        ->json('data'))->keyBy('name');

    expect($provinces)->toHaveCount(83)
        ->and(collect($provinces)->sum(fn ($p) => count($p['cities'])))->toBe(1634)
        ->and(collect($provinces['Oriental Mindoro']['cities'])->firstWhere('name', 'Calapan City'))
        ->toBe(['code' => '1705205000', 'name' => 'Calapan City', 'is_city' => true]);
});

it('uses the names people use and puts every city under the province people expect', function () {
    $provinceOf = fn (string $city) => City::where('name', $city)->sole()->province->name;

    expect($provinceOf('Cebu City'))->toBe('Cebu')
        ->and($provinceOf('Davao City'))->toBe('Davao del Sur')
        ->and($provinceOf('Baguio City'))->toBe('Benguet')
        ->and($provinceOf('Quezon City'))->toBe('Metro Manila')
        ->and($provinceOf('Pateros'))->toBe('Metro Manila')
        ->and($provinceOf('Baliwag City'))->toBe('Bulacan')
        ->and($provinceOf('Cagayan de Oro City'))->toBe('Misamis Oriental')
        ->and($provinceOf('Muñoz City'))->toBe('Nueva Ecija')
        ->and(City::where('name', 'Calapan City')->value('official_name'))->toBe('City of Calapan')
        ->and(Province::whereIn('name', ['Maguindanao del Norte', 'Maguindanao del Sur'])->count())->toBe(2);
});

it('can be loaded again without duplicates, keeping names up to date', function () {
    City::where('name', 'Calapan City')->update(['name' => 'Old name']);

    $this->artisan('dormlink:import-places')->assertSuccessful();

    expect(Province::count())->toBe(83)
        ->and(City::count())->toBe(1634)
        ->and(City::where('name', 'Calapan City')->exists())->toBeTrue();
});

it('serves a refreshed list after a re-import', function () {
    $this->getJson('/api/v1/places')->assertOk();
    Province::where('name', 'Oriental Mindoro')->update(['name' => 'Renamed']);
    app(PlaceImporter::class)->import();

    expect(collect($this->getJson('/api/v1/places')->json('data'))->pluck('name'))->toContain('Oriental Mindoro');
});

it('takes the province and city only from the list, the city under its province', function () {
    $owner = User::factory()->owner()->create();
    $create = fn (array $place) => $this->actingAs($owner, 'sanctum')->postJson('/api/v1/properties', [
        'name' => 'Santos House', 'type' => 'house', 'rental_mode' => 'whole', 'units' => ['capacity' => 2],
    ] + $place);

    $create(['province' => 'Oriental Mindoro', 'city' => 'Calapan City'])->assertCreated()
        ->assertJsonPath('data.city', 'Calapan City');
    $create(['province' => 'Mindoro', 'city' => 'Calapan City'])->assertUnprocessable()
        ->assertJsonPath('errors.province.0', 'Pick the province from the list.');
    $create(['province' => 'Cebu', 'city' => 'Calapan City'])->assertUnprocessable()
        ->assertJsonPath('errors.city.0', 'Pick the city or municipality from the list for Cebu.');
    $create(['city' => 'Calapan City'])->assertUnprocessable()
        ->assertJsonPath('errors.city.0', 'Pick the province first.');
});

it('keeps a location saved before the list until the owner changes it', function () {
    $owner = User::factory()->owner()->create();
    $property = makeProperty($owner);
    $property->forceFill(['province' => 'Oriental Mindoro', 'city' => 'Calapan'])->save();
    $update = fn (array $data) => $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/properties/{$property->id}", $data);

    $update(['name' => 'New name', 'province' => 'Oriental Mindoro', 'city' => 'Calapan'])->assertOk();
    $update(['city' => 'Calapan town'])->assertUnprocessable()
        ->assertJsonPath('errors.city.0', 'Pick the city or municipality from the list for Oriental Mindoro.');
    $update(['city' => 'Calapan City'])->assertOk();
    expect($property->fresh()->city)->toBe('Calapan City');
});

it('matches typed locations to the list: wording, case and accents do not matter', function (?string $province, ?string $city, array $expected) {
    $result = app(PlaceMatcher::class)->match($province, $city);

    expect([$result['status'], $result['province'], $result['city']])->toBe($expected);
})->with([
    'missing "City"' => ['Oriental Mindoro', 'Calapan', ['changed', 'Oriental Mindoro', 'Calapan City']],
    'PSA wording, lower case' => ['oriental mindoro', 'city of calapan', ['changed', 'Oriental Mindoro', 'Calapan City']],
    'already right' => ['Oriental Mindoro', 'Calapan City', ['ok', 'Oriental Mindoro', 'Calapan City']],
    'no accent typed' => ['Metro Manila', 'Las Pinas', ['changed', 'Metro Manila', 'Las Piñas City']],
    'NCR' => ['NCR', 'Makati', ['changed', 'Metro Manila', 'Makati City']],
    'province filled from a one-place city' => [null, 'Calapan City', ['changed', 'Oriental Mindoro', 'Calapan City']],
    'province only' => [' cebu ', null, ['changed', 'Cebu', null]],
    'unknown province' => ['Mindoro', 'Calapan', ['unmatched', 'Mindoro', 'Calapan']],
    'city not in that province' => ['Cebu', 'Calapan', ['unmatched', 'Cebu', 'Calapan']],
    'city in many provinces, no province' => [null, 'San Jose', ['unmatched', null, 'San Jose']],
    'nothing typed' => [null, '  ', ['ok', null, null]],
]);

it('shows the changes first and saves them only with --apply, keeping the listing\'s updated time', function () {
    $owner = User::factory()->owner()->create();
    $typed = makeProperty($owner);
    $typed->forceFill(['province' => 'Oriental Mindoro', 'city' => 'Calapan', 'updated_at' => '2026-10-01 08:00:00'])->save();
    $unclear = makeProperty($owner);
    $unclear->forceFill(['province' => 'Mindoro', 'city' => 'Calapan'])->save();

    $this->artisan('dormlink:match-places')
        ->expectsOutputToContain('1 to change, 1 not matched')
        ->assertSuccessful();
    expect($typed->fresh()->city)->toBe('Calapan');

    $this->artisan('dormlink:match-places --apply')->expectsOutputToContain('1 changed, 1 not matched')->assertSuccessful();
    expect($typed->fresh()->city)->toBe('Calapan City')
        ->and($typed->fresh()->updated_at->toDateTimeString())->toBe('2026-10-01 08:00:00')
        ->and($unclear->fresh()->province)->toBe('Mindoro');
});
