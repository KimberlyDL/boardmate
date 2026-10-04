<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\BookingApplication;
use App\Models\Property;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| A list must cost the same number of queries for 1 row as for many, so a
| per-row query (N+1) cannot slip back in unnoticed.
*/

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
});

afterEach(fn () => Date::setTestNow());

/** Counts the second of two identical requests, so one-off cache loads (roles) don't count. */
function countQueries(callable $request): int
{
    $request();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request()->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function publishedProperty(User $owner, int $beds = 2): Property
{
    $property = makeProperty($owner, ['units' => ['count' => $beds, 'rent_centavos' => 180000]]);
    $property->photos()->create(['path' => 'listing-photos/x-large.webp', 'thumb_path' => 'listing-photos/x-thumb.webp', 'is_cover' => true]);
    $property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();

    // A scheduled change and a reservation, so those paths are counted too.
    $unit = $property->units()->first();
    app(PriceBook::class)->set($unit, 190000, '2026-11-01', $owner);

    return $property;
}

function reviewerApplication(Property $property, ?User $boarder = null): BookingApplication
{
    $application = new BookingApplication(['planned_move_in_on' => '2026-10-20', 'contact_phone' => '09170000000']);
    $application->property_id = $property->id;
    $application->boarder_id = ($boarder ?? User::factory()->boarder()->create())->id;
    $application->save();

    return $application;
}

it('lists the Dorm Finder in the same number of queries for 1 or 20 listings', function () {
    publishedProperty($this->owner);
    $one = countQueries(fn () => $this->getJson('/api/v1/listings'));

    foreach (range(1, 19) as $i) {
        publishedProperty(User::factory()->owner()->create());
    }
    $many = countQueries(fn () => $this->getJson('/api/v1/listings')->assertJsonCount(20, 'data'));

    expect($many)->toBe($one);
});

it('shows a listing in the same number of queries for 1 or 30 bedspaces', function () {
    $small = publishedProperty($this->owner, 1);
    $large = publishedProperty($this->owner, 30);

    expect(countQueries(fn () => $this->getJson("/api/v1/listings/{$large->id}")))
        ->toBe(countQueries(fn () => $this->getJson("/api/v1/listings/{$small->id}")));
});

it('shows a property and its units in the same number of queries for 1 or 30 bedspaces', function () {
    $small = publishedProperty($this->owner, 1);
    $large = publishedProperty($this->owner, 30);
    foreach ([$small, $large] as $property) {
        $unit = $property->units()->first();
        app(BookingService::class)->approve(reviewerApplication($property), $unit->id, $this->owner);
    }
    $this->actingAs($this->owner, 'sanctum');

    expect(countQueries(fn () => $this->getJson("/api/v1/properties/{$large->id}")))
        ->toBe(countQueries(fn () => $this->getJson("/api/v1/properties/{$small->id}")))
        ->and(countQueries(fn () => $this->getJson("/api/v1/properties/{$large->id}/units")))
        ->toBe(countQueries(fn () => $this->getJson("/api/v1/properties/{$small->id}/units")));
});

it('lists properties in the same number of queries for 1 or 20', function () {
    $caretaker = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    assignCaretaker(publishedProperty($this->owner), $caretaker, 'manager');
    $oneOwner = countQueries(fn () => $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/properties'));
    $oneCaretaker = countQueries(fn () => $this->actingAs($caretaker, 'sanctum')->getJson('/api/v1/properties'));

    foreach (range(1, 19) as $i) {
        assignCaretaker(publishedProperty($this->owner), $caretaker, 'collector');
    }

    expect(countQueries(fn () => $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/properties')))->toBe($oneOwner)
        ->and(countQueries(fn () => $this->actingAs($caretaker, 'sanctum')->getJson('/api/v1/properties')))->toBe($oneCaretaker);
});

it('lists applications in the same number of queries for 1 or 25', function () {
    $addPair = function () {
        $property = publishedProperty($this->owner, 3);
        app(BookingService::class)->approve(reviewerApplication($property), $property->units()->first()->id, $this->owner);
        reviewerApplication($property);
    };
    $addPair();
    $this->actingAs($this->owner, 'sanctum');
    $pendingOne = countQueries(fn () => $this->getJson('/api/v1/applications'));
    $approvedOne = countQueries(fn () => $this->getJson('/api/v1/applications?status=approved'));

    foreach (range(1, 24) as $i) {
        $addPair();
    }

    expect(countQueries(fn () => $this->getJson('/api/v1/applications')->assertJsonCount(25, 'data')))->toBe($pendingOne)
        ->and(countQueries(fn () => $this->getJson('/api/v1/applications?status=approved')->assertJsonCount(25, 'data')))->toBe($approvedOne);
});

it('lists a boarder\'s applications in the same number of queries for 1 or 5', function () {
    $kim = User::factory()->boarder()->create();
    reviewerApplication(publishedProperty($this->owner), $kim);
    $this->actingAs($kim, 'sanctum');
    $one = countQueries(fn () => $this->getJson('/api/v1/me/applications'));

    foreach (range(1, 4) as $i) {
        reviewerApplication(publishedProperty(User::factory()->owner()->create()), $kim);
    }

    expect(countQueries(fn () => $this->getJson('/api/v1/me/applications')->assertJsonCount(5, 'data')))->toBe($one);
});

it('lists caretakers in the same number of queries for 1 or 10', function () {
    $property = publishedProperty($this->owner);
    assignCaretaker($property, User::factory()->caretakerFor($this->owner)->create(), 'collector');
    $this->actingAs($this->owner, 'sanctum');
    $one = countQueries(fn () => $this->getJson('/api/v1/owner/caretakers'));

    foreach (range(1, 9) as $i) {
        assignCaretaker($property, User::factory()->caretakerFor($this->owner)->create(), 'collector');
    }

    expect(countQueries(fn () => $this->getJson('/api/v1/owner/caretakers')))->toBe($one);
});

it('shows one application with its bookable units in the same number of queries for 1 or 30 beds', function () {
    $small = publishedProperty($this->owner, 1);
    $large = publishedProperty($this->owner, 30);
    $a = reviewerApplication($small);
    $b = reviewerApplication($large);
    $this->actingAs($this->owner, 'sanctum');

    expect(countQueries(fn () => $this->getJson("/api/v1/applications/{$b->id}")->assertJsonCount(30, 'meta.bookable_units')))
        ->toBe(countQueries(fn () => $this->getJson("/api/v1/applications/{$a->id}")));
});
