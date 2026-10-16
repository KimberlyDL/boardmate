<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\NotificationEvent;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\PriceRule;
use App\Models\Tenancy;
use App\Models\TenancyDiscount;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentLedger;
use App\Services\Notifications\DormLinkNotification;
use App\Services\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-09-01 08:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    // Two bedspaces at ₱1,800, priced from Sep 1.
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 180000]]);
    $this->bed = $this->property->units()->first();
    $this->water = $this->property->utilityAccounts()->create(['type' => 'water', 'name' => 'Water', 'method' => 'fixed_per_bedspace', 'billed_by' => 'owner']);
    app(PriceBook::class)->set($this->water, 30000, null, $this->owner);
    // Not on the anniversary bill: an actual bill, and one the boarders pay themselves.
    $this->property->utilityAccounts()->create(['type' => 'electricity', 'name' => 'Meralco', 'method' => 'actual_bill', 'billed_by' => 'owner']);
    $internet = $this->property->utilityAccounts()->create(['type' => 'internet', 'name' => 'Wi-Fi', 'method' => 'fixed_per_bedspace', 'billed_by' => 'group']);
    app(PriceBook::class)->set($internet, 15000, null, $this->owner);

    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->bed)->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

function billOn(string $day): array
{
    Date::setTestNow(CarbonImmutable::parse("{$day} 09:00", 'Asia/Manila'));

    return app(BillingService::class)->issueDuePeriodicBills(CarbonImmutable::parse($day, 'Asia/Manila'));
}

it('bills period 2 rent in advance and period 1 water in arrears on the first anniversary (SC-19)', function () {
    expect(billOn('2026-09-25')['issued'])->toBe(0)
        ->and(billOn('2026-10-24')['issued'])->toBe(0)
        ->and(billOn('2026-10-25')['issued'])->toBe(1);

    $bill = Bill::with('lines')->sole();
    expect($bill->kind)->toBe(BillKind::Periodic)
        ->and($bill->payer_id)->toBe($this->kim->id)
        ->and($bill->number)->toBe('BILL-2026-000001')
        ->and($bill->period_start_on->toDateString())->toBe('2026-10-26')
        ->and($bill->period_end_on->toDateString())->toBe('2026-11-25')
        ->and($bill->due_on->toDateString())->toBe('2026-10-25')
        ->and($bill->lines->map(fn ($l) => [$l->kind, $l->description, $l->amount_centavos])->all())->toBe([
            [BillLineKind::Rent, 'Rent, Oct 26 – Nov 25, 2026', 180000],
            [BillLineKind::FixedUtility, 'Water, Sep 26 – Oct 25, 2026', 30000],
        ])
        ->and($bill->totalCentavos())->toBe(210000)
        ->and(PriceRule::whereKey($bill->lines->pluck('price_rule_id'))->whereNull('locked_at')->count())->toBe(0);

    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn (DormLinkNotification $n) => $n->event === NotificationEvent::BillIssued && $n->data['total'] === '₱2,100.00' && $n->data['due_on'] === 'Oct 25, 2026');

    // Running the same day again issues nothing.
    expect(billOn('2026-10-25')['issued'])->toBe(0)
        ->and(Bill::count())->toBe(1);
});

it('catches up missed days, one bill per period', function () {
    expect(billOn('2026-12-01')['issued'])->toBe(2)
        ->and(Bill::orderBy('id')->pluck('due_on')->map(fn ($d) => $d->toDateString())->all())->toBe(['2026-10-25', '2026-11-25'])
        ->and(Bill::orderBy('id')->pluck('number')->all())->toBe(['BILL-2026-000001', 'BILL-2026-000002']);
});

it('takes the agreed rate off the rent and locks the discount', function () {
    $discount = TenancyDiscount::factory()->create(['tenancy_id' => $this->tenancy->id, 'kind' => 'fixed', 'value' => 50000, 'effective_from' => '2026-09-25']);

    billOn('2026-10-25');

    $rent = Bill::sole()->lines()->where('kind', 'rent')->sole();
    expect($rent->amount_centavos)->toBe(130000)
        ->and($rent->description)->toBe('Rent, Oct 26 – Nov 25, 2026 (agreed rate, ₱500.00 off)')
        ->and($rent->discount_id)->toBe($discount->id)
        ->and($discount->fresh()->locked_at)->not->toBeNull();
});

it('applies a price change from the first period that starts on or after its date (SC-04)', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Manila'));
    app(PriceBook::class)->set($this->bed, 200000, '2026-11-01', $this->owner);

    billOn('2026-11-25');

    expect(Bill::orderBy('id')->get()->map(fn ($b) => $b->lines()->where('kind', 'rent')->value('amount_centavos'))->all())->toBe([180000, 200000]);
});

it('bills an anniversary on the 31st on the last day of shorter months', function () {
    $tenancy = Tenancy::factory()->forUnit($this->property->units()->get()[1])->movedInOn('2026-10-31')->create();

    billOn('2026-11-30');

    $bill = Bill::where('tenancy_id', $tenancy->id)->sole();
    expect($bill->due_on->toDateString())->toBe('2026-11-30')
        ->and($bill->period_start_on->toDateString())->toBe('2026-12-01')
        ->and($bill->period_end_on->toDateString())->toBe('2026-12-31');
});

it('puts "fixed per property" utilities on whole-room tenancies only, and room meters on their room only', function () {
    $whole = makeProperty($this->owner, ['rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 450000]]);
    $house = $whole->units()->first();
    $water = $whole->utilityAccounts()->create(['type' => 'water', 'name' => 'Water', 'method' => 'fixed_per_property', 'billed_by' => 'owner']);
    app(PriceBook::class)->set($water, 50000, null, $this->owner);
    $leader = Tenancy::factory()->forUnit($house)->movedInOn('2026-09-25')->create();

    $otherRoom = $this->property->rooms()->create(['number' => 2, 'rental_mode' => 'bedspaces']);
    $roomWifi = $this->property->utilityAccounts()->create(['room_id' => $otherRoom->id, 'type' => 'internet', 'name' => 'Room Wi-Fi', 'method' => 'fixed_per_bedspace', 'billed_by' => 'owner']);
    app(PriceBook::class)->set($roomWifi, 10000, null, $this->owner);

    billOn('2026-10-25');

    expect(Bill::where('tenancy_id', $leader->id)->sole()->lines()->pluck('description')->all())
        ->toBe(['Rent, Oct 26 – Nov 25, 2026', 'Water, Sep 26 – Oct 25, 2026'])
        ->and(Bill::where('tenancy_id', $this->tenancy->id)->sole()->lines()->pluck('description')->all())
        ->toBe(['Rent, Oct 26 – Nov 25, 2026', 'Water, Sep 26 – Oct 25, 2026']);
});

it('runs from the daily scheduler', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-25 09:00', 'Asia/Manila'));
    $this->artisan('dormlink:daily')->assertSuccessful();

    expect(Bill::where('tenancy_id', $this->tenancy->id)->count())->toBe(1);
});

it('applies an early payment to the bill when it is issued (SC-34)', function () {
    Payment::factory()->forPayer($this->property, $this->kim)->confirmed()->create(['amount_centavos' => 400000, 'paid_on' => '2026-10-20']);

    billOn('2026-10-25');

    $bill = Bill::sole();
    expect($bill->totalCentavos())->toBe(210000)
        ->and($bill->balanceCentavos())->toBe(0)
        ->and(app(PaymentLedger::class)->summary($this->property->id, $this->kim->id))->toBe(['owed_centavos' => 0, 'credit_centavos' => 190000]);
});

it('takes Kim\'s Oct 25 bill from issued to paid: cash, then a confirmed GCash report (worked example)', function () {
    billOn('2026-10-25');
    $bill = Bill::sole();
    expect($bill->totalCentavos())->toBe(210000);

    Date::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Manila'));
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/payments", [
        'payer_id' => $this->kim->id, 'amount_centavos' => 200000, 'method' => 'cash', 'paid_on' => '2026-10-26',
    ])->assertCreated()->assertJsonPath('data.receipt_number', 'OR-2026-000001');
    // Rent first: ₱1,800 rent paid, ₱200 of the ₱300 water.
    expect($bill->balanceCentavos())->toBe(10000);

    $reported = $this->actingAs($this->kim, 'sanctum')->postJson('/api/v1/me/payments', [
        'property_id' => $this->property->id, 'amount_centavos' => 10000, 'method' => 'e_wallet', 'reference' => '5012 345 678', 'paid_on' => '2026-10-26',
    ])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/me/bills')->assertJsonPath('meta.places.0.owed_centavos', 10000);

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/payments/{$reported}/confirm")
        ->assertOk()->assertJsonPath('data.receipt_number', 'OR-2026-000002');
    expect($bill->balanceCentavos())->toBe(0);
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/bills?status=paid')
        ->assertJsonPath('data.0.status', 'paid')
        ->assertJsonPath('meta.places.0.owed_centavos', 0);
});
