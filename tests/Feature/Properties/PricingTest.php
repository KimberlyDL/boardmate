<?php

use App\Models\PriceRule;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/*
| S4: a price is never overwritten. The old rule closes the day before the
| new one starts; history stays.
*/

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->unit = makeProperty($this->owner, ['units' => ['count' => 1, 'rent_centavos' => 180000]])->units()->first();
    $this->prices = app(PriceBook::class);
});

afterEach(fn () => Date::setTestNow());

it('closes the current rent and opens a new one from the chosen date (SC-04)', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson("/api/v1/units/{$this->unit->id}/rent", ['amount_centavos' => 200000, 'effective_from' => '2026-11-01'])
        ->assertOk()
        ->assertJsonPath('data.rent_centavos', 180000)
        ->assertJsonPath('data.upcoming_rent.amount_centavos', 200000)
        ->assertJsonPath('data.upcoming_rent.effective_from', '2026-11-01');

    expect($this->prices->amountOn($this->unit, '2026-10-31'))->toBe(180000)
        ->and($this->prices->amountOn($this->unit, '2026-11-01'))->toBe(200000);

    $this->getJson("/api/v1/units/{$this->unit->id}/price-history")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.state', 'scheduled')
        ->assertJsonPath('data.1.state', 'current')
        ->assertJsonPath('data.1.effective_to', '2026-10-31');
});

it('replaces a scheduled change instead of stacking them', function () {
    $this->prices->set($this->unit, 200000, '2026-11-01', $this->owner);
    $this->prices->set($this->unit, 210000, '2026-11-15', $this->owner);
    $this->prices->set($this->unit, 190000, '2026-11-01', $this->owner); // replaces both future rules

    expect($this->prices->amountOn($this->unit, '2026-11-20'))->toBe(190000)
        ->and(PriceRule::where('priceable_id', $this->unit->id)->count())->toBe(2);
});

it('never changes a price that was already in force on earlier days', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->prices->set($this->unit, 200000, null, $this->owner);

    // The ₱1,800 rule that applied Oct 4–19 is kept, just closed.
    expect($this->prices->amountOn($this->unit, '2026-10-10'))->toBe(180000)
        ->and($this->prices->amountOn($this->unit, '2026-10-20'))->toBe(200000);
});

it('lets a price set today be corrected the same day without leaving a trace rule', function () {
    $this->prices->set($this->unit, 185000, null, $this->owner); // typo fix on the creation day

    expect($this->prices->amountOn($this->unit))->toBe(185000)
        ->and(PriceRule::where('priceable_id', $this->unit->id)->count())->toBe(1);
});

it('treats saving the price already in force as no change', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson("/api/v1/units/{$this->unit->id}/rent", ['amount_centavos' => 180000])
        ->assertOk()->assertJsonPath('message', 'That is already the rent. Nothing changed.');

    expect(PriceRule::where('priceable_id', $this->unit->id)->count())->toBe(1);
    $this->getJson('/api/v1/owner/audit-log?event=price.rent_changed')->assertJsonCount(0, 'data');
});

it('refuses back-dated or negative prices', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson("/api/v1/units/{$this->unit->id}/rent", ['amount_centavos' => 200000, 'effective_from' => '2026-09-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['effective_from']);

    $this->putJson("/api/v1/units/{$this->unit->id}/rent", ['amount_centavos' => -1])->assertUnprocessable();
});

it('records rent changes in the activity log', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson("/api/v1/units/{$this->unit->id}/rent", ['amount_centavos' => 200000, 'effective_from' => '2026-11-01']);

    $this->getJson('/api/v1/owner/audit-log?event=price.rent_changed')
        ->assertJsonPath('data.0.changes.0.old', '₱1,800.00')
        ->assertJsonPath('data.0.changes.0.new', '₱2,000.00');
});

it('prices fixed utilities with history, and needs an amount only for fixed methods', function () {
    $property = $this->unit->property;
    $this->actingAs($this->owner, 'sanctum');

    $this->postJson("/api/v1/properties/{$property->id}/utility-accounts", ['type' => 'internet', 'method' => 'fixed_per_bedspace'])
        ->assertUnprocessable()->assertJsonValidationErrors(['amount_centavos']);

    $water = $this->postJson("/api/v1/properties/{$property->id}/utility-accounts", ['type' => 'water', 'method' => 'actual_bill'])
        ->assertCreated()->assertJsonPath('data.amount_centavos', null)->assertJsonPath('data.billed_by', 'owner');

    $net = $this->postJson("/api/v1/properties/{$property->id}/utility-accounts", [
        'type' => 'internet', 'method' => 'opt_in', 'amount_centavos' => 15000,
    ])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/utility-accounts/{$net}/amount", ['amount_centavos' => 20000, 'effective_from' => '2026-11-01'])
        ->assertOk()->assertJsonPath('data.amount_centavos', 15000)->assertJsonPath('data.upcoming_amount.amount_centavos', 20000);

    $this->putJson('/api/v1/utility-accounts/'.$water->json('data.id').'/amount', ['amount_centavos' => 1])
        ->assertUnprocessable();
});

it('lets the boarders handle a utility themselves (billed by group, SC-31)', function () {
    $property = $this->unit->property;

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$property->id}/utility-accounts", [
            'type' => 'internet', 'method' => 'actual_bill', 'billed_by' => 'group',
        ])->assertCreated()->assertJsonPath('data.billed_by', 'group');
});
