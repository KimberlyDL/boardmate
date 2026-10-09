<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\Tenancy;
use App\Models\TenancyDiscount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();

    // Kim rents a ₱4,500 house on her own.
    $this->property = makeProperty($this->owner, ['rental_mode' => 'whole', 'units' => ['capacity' => 2, 'rent_centavos' => 450000]]);
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);

    $this->url = "/api/v1/tenancies/{$this->tenancy->id}/discount";
});

afterEach(fn () => Date::setTestNow());

it('gives Kim an agreed rate from today and shows it on her tenancy', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])
        ->assertOk()
        ->assertJsonPath('message', 'Discount saved.')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.label', 'Agreed rate')
        ->assertJsonPath('data.effective_from', '2026-10-25');

    $this->getJson("/api/v1/tenancies/{$this->tenancy->id}")
        ->assertJsonPath('data.rent_centavos', 450000)
        ->assertJsonPath('data.discount.amount_centavos', 50000)
        ->assertJsonPath('data.rent_after_discount_centavos', 400000);

    $this->getJson('/api/v1/owner/audit-log?event=tenancy.discount_set')
        ->assertJsonPath('data.0.changes.0.new', '₱500.00 off');
});

it('takes a percent discount off the rent', function () {
    $this->actingAs($this->owner, 'sanctum')->putJson($this->url, ['kind' => 'percent', 'value' => 1000])->assertOk();

    $this->getJson("/api/v1/tenancies/{$this->tenancy->id}")
        ->assertJsonPath('data.discount.amount_centavos', 45000)
        ->assertJsonPath('data.rent_after_discount_centavos', 405000);
});

it('changes the discount from a later date and keeps the old one for the days before', function () {
    $this->actingAs($this->owner, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])->assertOk();

    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 100000, 'effective_from' => '2026-11-26'])
        ->assertOk()->assertJsonPath('data.status', 'scheduled');

    $history = $this->getJson("/api/v1/tenancies/{$this->tenancy->id}/discounts")->assertOk()->json('data');
    expect($history)->toHaveCount(2)
        ->and($history[0])->toMatchArray(['value' => 100000, 'status' => 'scheduled', 'effective_to' => null])
        ->and($history[1])->toMatchArray(['value' => 50000, 'status' => 'active', 'effective_to' => '2026-11-25']);

    // Kim is still on the old discount today, with the new one waiting.
    $this->getJson("/api/v1/tenancies/{$this->tenancy->id}")
        ->assertJsonPath('data.discount.amount_centavos', 50000)
        ->assertJsonPath('data.scheduled_discount.effective_from', '2026-11-26')
        ->assertJsonPath('data.scheduled_discount.value', 100000);
});

it('replaces a scheduled discount instead of stacking another', function () {
    $this->actingAs($this->owner, 'sanctum');
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 50000, 'effective_from' => '2026-11-26'])->assertOk();
    $this->putJson($this->url, ['kind' => 'percent', 'value' => 500, 'effective_from' => '2026-11-26'])->assertOk();
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 70000, 'effective_from' => '2026-11-01'])->assertOk();

    $rows = TenancyDiscount::where('tenancy_id', $this->tenancy->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->value)->toBe(70000)
        ->and($rows->first()->effective_from->toDateString())->toBe('2026-11-01');
});

it('does nothing when the discount is already in force', function () {
    $this->actingAs($this->owner, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])->assertOk();

    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 50000, 'effective_from' => '2026-11-01'])
        ->assertOk()->assertJsonPath('message', 'That is already the discount. Nothing changed.');

    expect(TenancyDiscount::count())->toBe(1);
    $this->getJson('/api/v1/owner/audit-log?event=tenancy.discount_set')->assertJsonCount(1, 'data');
});

it('corrects a discount set today on the same day, but never back-dates', function () {
    $this->actingAs($this->owner, 'sanctum');
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])->assertOk();
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 60000])->assertOk();

    expect(TenancyDiscount::pluck('value')->all())->toBe([60000]);

    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 80000, 'effective_from' => '2026-10-24'])
        ->assertUnprocessable()->assertJsonValidationErrors(['effective_from']);
});

it('checks the amount', function () {
    $this->actingAs($this->owner, 'sanctum');

    $this->putJson($this->url, ['kind' => 'percent', 'value' => 10001])->assertUnprocessable()->assertJsonValidationErrors(['value']);
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 450001])->assertUnprocessable()->assertJsonValidationErrors(['value']);
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 0])->assertUnprocessable()->assertJsonValidationErrors(['value']);
    $this->putJson($this->url, ['kind' => 'weekly', 'value' => 100])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
    expect(TenancyDiscount::count())->toBe(0);
});

it('never replaces a discount a bill already used', function () {
    $this->actingAs($this->owner, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])->assertOk();
    TenancyDiscount::query()->update(['locked_at' => now()]);

    // Starting before or on the locked discount's date is refused; starting later only closes it.
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 90000])->assertUnprocessable()->assertJsonValidationErrors(['effective_from']);
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 90000, 'effective_from' => '2026-10-26'])->assertOk();

    $rows = TenancyDiscount::orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->value)->toBe(50000)
        ->and($rows[0]->effective_to->toDateString())->toBe('2026-10-25');
});

it('ends the discount from a date', function () {
    $this->actingAs($this->owner, 'sanctum');
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 50000, 'effective_from' => '2026-10-25'])->assertOk();
    // Move the row into the past so it counts as already in force.
    TenancyDiscount::query()->update(['effective_from' => '2026-09-25', 'created_at' => '2026-09-25 10:00:00']);

    $this->deleteJson($this->url, ['effective_from' => '2026-12-01'])->assertOk();
    expect(TenancyDiscount::sole()->effective_to->toDateString())->toBe('2026-11-30');

    $this->deleteJson($this->url, ['effective_from' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors(['discount']);
    $this->getJson('/api/v1/owner/audit-log?event=tenancy.discount_ended')->assertJsonPath('data.0.event', 'tenancy.discount_ended');
});

it('removes a discount that was only scheduled', function () {
    $this->actingAs($this->owner, 'sanctum');
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 50000, 'effective_from' => '2026-11-26'])->assertOk();

    $this->deleteJson($this->url)->assertOk();

    expect(TenancyDiscount::count())->toBe(0);
});

it('lets a Manager change the discount but not a Collector, and hides the tenancy from outsiders', function () {
    $this->actingAs($this->manager, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])->assertOk();
    $this->actingAs($this->collector, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 60000])->assertForbidden();
    $this->actingAs($this->collector, 'sanctum')->deleteJson($this->url)->assertForbidden();
    $this->getJson("/api/v1/tenancies/{$this->tenancy->id}/discounts")->assertOk()->assertJsonCount(1, 'data');

    $stranger = User::factory()->boarder()->create();
    $otherOwner = User::factory()->owner()->create();
    foreach ([$stranger, $otherOwner] as $outsider) {
        $this->actingAs($outsider, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 60000])->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/tenancies/{$this->tenancy->id}/discounts")->assertNotFound();
    }
    expect(TenancyDiscount::sole()->value)->toBe(50000);
});

it('lets the tenant read her discount history but not change it', function () {
    $this->actingAs($this->owner, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])->assertOk();

    $this->actingAs($this->kim, 'sanctum')->getJson("/api/v1/tenancies/{$this->tenancy->id}/discounts")->assertOk()->assertJsonCount(1, 'data');
    $this->putJson($this->url, ['kind' => 'fixed', 'value' => 400000])->assertNotFound();
    $this->deleteJson($this->url)->assertNotFound();
});

it('refuses to change the discount of a tenancy that has ended', function () {
    DB::table('tenancies')->where('id', $this->tenancy->id)->update(['status' => 'ended']);

    $this->actingAs($this->owner, 'sanctum')->putJson($this->url, ['kind' => 'fixed', 'value' => 50000])
        ->assertUnprocessable()->assertJsonValidationErrors(['discount']);
});
