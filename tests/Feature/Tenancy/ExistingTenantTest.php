<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Models\Bill;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Pricing\PriceBook;
use App\Services\Tenancies\TenancyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/*
| Existing tenants: someone already living at the place before it used
| DormLink keeps their real move-in date, gets no back bills, and is billed
| from their next due date.
*/

beforeEach(function () {
    Notification::fake();
    // The place was set up in DormLink on Oct 1 (prices cannot be back-dated); the tenants are added on Nov 8.
    Date::setTestNow(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    // Bedspaces at ₱2,000, with water ₱300 a period billed by the owner.
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $this->bed = $this->property->units()->first();
    $water = $this->property->utilityAccounts()->create(['type' => 'water', 'name' => 'Water', 'method' => 'fixed_per_bedspace', 'billed_by' => 'owner']);
    app(PriceBook::class)->set($water, 30000, null, $this->owner);

    Date::setTestNow(CarbonImmutable::parse('2026-11-08 10:00', 'Asia/Manila'));

    $this->ana = User::factory()->boarder()->create(['name' => 'Ana']);
    $this->url = "/api/v1/properties/{$this->property->id}/tenancies";
});

afterEach(fn () => Date::setTestNow());

/** A walk-in request for Ana's bedspace, with no money recorded. */
function existingBody(array $override = []): array
{
    return array_replace_recursive([
        'unit_id' => test()->bed->id,
        'email' => test()->ana->email,
        'moved_in_on' => '2026-03-10',
        'existing_tenant' => true,
        'emergency_contact' => ['name' => 'Rosa Reyes', 'phone' => '0917 555 0000'],
    ], $override);
}

function billExistingOn(string $day): int
{
    Date::setTestNow(CarbonImmutable::parse("{$day} 09:00", 'Asia/Manila'));

    return app(BillingService::class)->issueDuePeriodicBills(CarbonImmutable::parse($day, 'Asia/Manila'))['issued'];
}

it('bills an existing tenant once, on the next due date, with no back bills', function () {
    // Activation is required at this place (the default), yet no deposit or first rent is asked for.
    $this->actingAs($this->owner, 'sanctum')
        ->getJson("{$this->url}/preview?unit_id={$this->bed->id}&moved_in_on=2026-03-10&existing_tenant=1")
        ->assertOk()
        ->assertJsonPath('data.billing_starts_on', '2026-11-11')
        ->assertJsonPath('data.first_bill_due_on', '2026-11-10');

    $this->postJson($this->url, existingBody())
        ->assertCreated()
        ->assertJsonPath('data.moved_in_on', '2026-03-10')
        ->assertJsonPath('data.is_existing_tenant', true)
        ->assertJsonPath('data.billing_starts_on', '2026-11-11')
        ->assertJsonPath('data.next_rent_due_on', '2026-11-10');

    expect(Tenancy::sole()->payments()->count())->toBe(0);

    // March to October were settled before DormLink: nothing today or tomorrow.
    expect(billExistingOn('2026-11-08'))->toBe(0)
        ->and(billExistingOn('2026-11-09'))->toBe(0)
        ->and(billExistingOn('2026-11-10'))->toBe(1)
        ->and(billExistingOn('2026-11-10'))->toBe(0); // a rerun adds nothing

    $bill = Bill::with('lines')->sole();
    expect($bill->kind)->toBe(BillKind::Periodic)
        ->and($bill->due_on->toDateString())->toBe('2026-11-10')
        ->and($bill->lines->map(fn ($l) => [$l->kind, $l->description, $l->amount_centavos])->all())->toBe([
            [BillLineKind::Rent, 'Rent, Nov 11 – Dec 10, 2026', 200000],
            // The month before is billed in arrears like any other: it was not collected yet.
            [BillLineKind::FixedUtility, 'Water, Oct 11 – Nov 10, 2026', 30000],
        ]);

    // The month after goes on as usual.
    expect(billExistingOn('2026-12-10'))->toBe(1)
        ->and(Bill::count())->toBe(2);
});

it('bills a utility priced only today on the existing tenant\'s first bill', function () {
    // Wi-Fi is priced in DormLink only today, after last month began (prices cannot be back-dated).
    $wifi = $this->property->utilityAccounts()->create(['type' => 'internet', 'name' => 'Wi-Fi', 'method' => 'fixed_per_bedspace', 'billed_by' => 'owner']);
    app(PriceBook::class)->set($wifi, 20000, null, $this->owner);

    $this->actingAs($this->owner, 'sanctum')->postJson($this->url, existingBody())->assertCreated();

    expect(billExistingOn('2026-11-10'))->toBe(1)
        ->and(Bill::with('lines')->sole()->lines->map(fn ($l) => [$l->description, $l->amount_centavos])->all())->toBe([
            ['Rent, Nov 11 – Dec 10, 2026', 200000],
            ['Water, Oct 11 – Nov 10, 2026', 30000],
            ['Wi-Fi, Oct 11 – Nov 10, 2026', 20000],
        ]);
});

it('bills today when the existing tenant\'s rent is due today', function () {
    // Moved in on May 8; today is Nov 8, a due date.
    $this->actingAs($this->owner, 'sanctum')->postJson($this->url, existingBody(['moved_in_on' => '2026-05-08']))
        ->assertCreated()
        ->assertJsonPath('data.billing_starts_on', '2026-11-09');

    expect(billExistingOn('2026-11-08'))->toBe(1);

    $bill = Bill::sole();
    expect($bill->due_on->toDateString())->toBe('2026-11-08')
        ->and($bill->period_start_on->toDateString())->toBe('2026-11-09');
});

it('keeps the date limits for both kinds of move-in', function () {
    $this->actingAs($this->owner, 'sanctum');
    $normal = existingBody([
        'existing_tenant' => false,
        'deposit' => ['amount_centavos' => 200000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 200000, 'method' => 'cash'],
    ]);

    // A normal move-in: at most 30 days back.
    $this->postJson($this->url, ['moved_in_on' => '2026-10-08'] + $normal)->assertUnprocessable()->assertJsonValidationErrors(['moved_in_on']);
    // An existing tenant: before today, at most 10 years back.
    $this->postJson($this->url, existingBody(['moved_in_on' => '2026-11-08']))->assertUnprocessable()->assertJsonValidationErrors(['moved_in_on']);
    $this->postJson($this->url, existingBody(['moved_in_on' => '2016-11-07']))->assertUnprocessable()->assertJsonValidationErrors(['moved_in_on']);

    expect(Tenancy::count())->toBe(0)
        ->and(TenancyService::EXISTING_MAX_YEARS_BACK)->toBe(10);
});

it('records what an existing tenant still owes from before as one one-time charge', function () {
    $this->actingAs($this->owner, 'sanctum');

    // Only for an existing tenant.
    $this->postJson($this->url, existingBody([
        'existing_tenant' => false,
        'moved_in_on' => '2026-11-08',
        'opening_balance_centavos' => 350000,
        'deposit' => ['amount_centavos' => 200000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 200000, 'method' => 'cash'],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['opening_balance_centavos']);

    $this->postJson($this->url, existingBody(['opening_balance_centavos' => 350000]))->assertCreated();

    $bill = Bill::with('lines')->sole();
    expect($bill->kind)->toBe(BillKind::Charge)
        ->and($bill->tenancy_id)->toBe(Tenancy::sole()->id)
        ->and($bill->payer_id)->toBe($this->ana->id)
        ->and($bill->totalCentavos())->toBe(350000)
        ->and($bill->lines->sole()->kind)->toBe(BillLineKind::OneTime)
        ->and($bill->lines->sole()->description)->toBe(TenancyService::OPENING_BALANCE_DESCRIPTION);

    // The balance does not count as a rent bill: the first rent bill still comes on the due date.
    expect(billExistingOn('2026-11-10'))->toBe(1)
        ->and(Bill::where('kind', BillKind::Periodic)->count())->toBe(1);
});
