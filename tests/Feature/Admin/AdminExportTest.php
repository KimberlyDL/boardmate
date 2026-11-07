<?php

use App\Enums\UnitStatus;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-08 10:00', 'Asia/Manila'));
    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => Date::setTestNow());

/** Download an export the way the app does: link first, then the signed link. */
function exportCsv(string $key): array
{
    $link = test()->actingAs(test()->admin, 'sanctum')->getJson("/api/v1/admin/exports/{$key}/link")->assertOk();
    expect($link->json('data.filename'))->toBe("dormlink-admin-{$key}-2026-11-08.csv");

    $body = test()->get($link->json('data.url'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();

    return array_map('str_getcsv', array_filter(explode("\n", substr($body, 3))));
}

it('lists the exports and gives only the admin a link', function () {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/exports')->assertOk()
        ->assertJsonPath('data.*.key', ['summary', 'owners', 'properties', 'accounts']);

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson('/api/v1/admin/exports/owners/link')->assertForbidden();
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/exports/money/link')->assertNotFound();
    $this->get('/api/v1/admin/exports/owners/download')->assertForbidden();
});

it('exports owners and properties with counts, emails and no money or phone numbers', function () {
    $owner = User::factory()->owner()->create(['name' => 'Kim Santos', 'email' => 'kim@example.com', 'phone' => '09170000000']);
    $property = makeProperty($owner, ['units' => ['count' => 3, 'rent_centavos' => 180000]]);
    $property->forceFill(['name' => 'Santos House', 'province' => 'Oriental Mindoro', 'city' => 'Calapan City', 'is_published' => true, 'published_at' => '2026-11-01'])->save();
    $bed = $property->units()->first();
    Tenancy::factory()->forUnit($bed)->movedInOn('2026-11-01')->create();
    $bed->forceFill(['status' => UnitStatus::Occupied])->save();

    $owners = exportCsv('owners');
    expect($owners[0])->toBe(['Owner ID', 'Name', 'Email', 'Business name', 'Review status', 'Applied on', 'Reviewed on', 'Suspended', 'Properties', 'On the Finder', 'Places', 'Joined on'])
        ->and($owners[1][1])->toBe('Kim Santos')
        ->and($owners[1][2])->toBe('kim@example.com')
        ->and(array_slice($owners[1], 7, 4))->toBe(['no', '1', '1', '3']);

    $properties = exportCsv('properties');
    expect($properties[1])->toMatchArray([1 => 'Santos House', 3 => 'kim@example.com', 5 => 'Oriental Mindoro', 6 => 'Calapan City', 8 => 'yes', 9 => '2026-11-01'])
        ->and(array_slice($properties[1], 11, 3))->toBe(['3', '1', '2']);

    $everything = implode("\n", array_merge(...array_map(fn ($rows) => array_merge(...$rows), [$owners, $properties, exportCsv('accounts')])));
    expect($everything)->not->toContain('09170000000')->not->toContain('1800');
});

it('exports accounts without admins, and the Overview summary', function () {
    User::factory()->boarder()->create(['email' => 'ben@example.com', 'created_at' => '2026-10-15']);

    $accounts = exportCsv('accounts');
    expect(collect($accounts)->pluck(2)->all())->toBe(['Email', 'ben@example.com'])
        ->and($accounts[1][3])->toBe('Boarder')
        ->and($accounts[1][6])->toBe('2026-10-15');

    $summary = collect(exportCsv('summary'));
    expect($summary->first())->toBe(['Section', 'Figure', 'Value'])
        ->and($summary->firstWhere(1, 'Accounts')[2])->toBe('1')
        ->and($summary->where(0, 'Month 2026-10')->firstWhere(1, 'Sign-ups')[2])->toBe('1');
});
