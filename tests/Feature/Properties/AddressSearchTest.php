<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/** What OpenStreetMap answers for "naujan" and for a street (trimmed). */
function osmAnswer(): array
{
    return [
        ['name' => 'Naujan', 'addresstype' => 'town', 'lat' => '13.3242390', 'lon' => '121.3030659',
            'address' => ['town' => 'Naujan', 'state' => 'Oriental Mindoro', 'region' => 'Mimaropa', 'postcode' => '5204', 'country' => 'Philippines']],
        ['name' => 'J. P. Rizal Street', 'addresstype' => 'road', 'lat' => '13.3933341', 'lon' => '121.1671697',
            'address' => ['road' => 'J. P. Rizal Street', 'quarter' => 'Tawiran', 'village' => 'Masipit', 'city' => 'Calapan', 'state' => 'Oriental Mindoro', 'country' => 'Philippines']],
        ['display_name' => 'No position'],
    ];
}

beforeEach(fn () => RateLimiter::clear('geocoder'));

it('finds places in the Philippines with a short area line, without signing in', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(osmAnswer())]);

    $this->getJson('/api/v1/places/search?q=naujan')->assertOk()->assertExactJson(['data' => [
        ['name' => 'Naujan', 'area' => 'Oriental Mindoro', 'label' => 'Naujan, Oriental Mindoro', 'kind' => 'town', 'latitude' => 13.324239, 'longitude' => 121.3030659],
        ['name' => 'J. P. Rizal Street', 'area' => 'Tawiran, Masipit, Calapan, Oriental Mindoro', 'label' => 'J. P. Rizal Street, Tawiran, Masipit, Calapan, Oriental Mindoro', 'kind' => 'road', 'latitude' => 13.3933341, 'longitude' => 121.1671697],
    ]]);

    Http::assertSent(fn (Request $r) => $r['countrycodes'] === 'ph' && $r['q'] === 'naujan'
        && str_starts_with($r->header('User-Agent')[0], 'DormLink/'));
});

it('asks OpenStreetMap once for the same words, however they are typed', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(osmAnswer())]);

    $this->getJson('/api/v1/places/search?q=Naujan')->assertOk();
    $this->getJson('/api/v1/places/search?q=%20naujan%20%20')->assertOk()->assertJsonPath('data.0.name', 'Naujan');

    Http::assertSentCount(1);
});

it('says so when the address service cannot answer, and needs at least three letters', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('down', 503)]);

    $this->getJson('/api/v1/places/search?q=naujan')->assertUnprocessable()
        ->assertJsonPath('errors.q.0', 'Address search is not available right now. Try again later.');
    $this->getJson('/api/v1/places/search?q=na')->assertUnprocessable()->assertJsonValidationErrors(['q']);
});

it('uses LocationIQ instead when its address and key are set', function () {
    config(['services.geocoder.url' => 'https://us1.locationiq.com/v1', 'services.geocoder.key' => 'test-key']);
    Http::fake(['us1.locationiq.com/*' => Http::response([])]);

    $this->getJson('/api/v1/places/search?q=nowhere%20at%20all')->assertOk()->assertExactJson(['data' => []]);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://us1.locationiq.com/v1/search') && $r['key'] === 'test-key');
});
