<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TenancyStatus;
use App\Enums\UnitStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Finance\FinanceOverview;
use App\Services\Properties\PropertySetup;
use App\Services\Properties\UnitStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    $this->house = makeProperty($this->owner, ['details' => ['name' => 'Santos House'], 'units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $this->annex = makeProperty($this->owner, ['details' => ['name' => 'Annex'], 'units' => ['count' => 1, 'rent_centavos' => 200000]]);
    assignCaretaker($this->house, $this->manager, 'manager');
    assignCaretaker($this->house, $this->collector, 'collector');

    [$bed1, $bed2] = $this->house->units()->get();
    $bed1->forceFill(['status' => UnitStatus::Occupied])->save();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->kimStay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
    $lea = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-10-05')->create();
    $ana = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-06-01')->create(['status' => TenancyStatus::Settled, 'moved_out_on' => '2026-09-30']);

    // Move-in money: Kim in September, Lea this month; Ana's deposit was settled this month.
    TenancyPayment::factory()->create(['tenancy_id' => $this->kimStay->id, 'amount_centavos' => 400000, 'received_on' => '2026-09-01']);
    TenancyPayment::factory()->firstRent()->create(['tenancy_id' => $this->kimStay->id, 'amount_centavos' => 200000, 'received_on' => '2026-09-01']);
    TenancyPayment::factory()->create(['tenancy_id' => $lea->id, 'amount_centavos' => 400000, 'received_on' => '2026-10-05']);
    TenancyPayment::factory()->firstRent()->create(['tenancy_id' => $lea->id, 'amount_centavos' => 200000, 'received_on' => '2026-10-05']);
    TenancyPayment::factory()->create(['tenancy_id' => $ana->id, 'amount_centavos' => 300000, 'received_on' => '2026-06-01']);
    Settlement::factory()->forTenancy($ana)->create(['deposit_held_centavos' => 300000, 'refund_centavos' => 250000, 'kept_centavos' => 50000, 'refunded_on' => '2026-10-15']);

    // Kim's bills this month: ₱2,300 monthly (rent + fixed utility), a ₱500 charge less a ₱100 correction, a ₱400 room share.
    $monthly = Bill::factory()->forTenancy($this->kimStay)->create(['issued_on' => '2026-10-01', 'due_on' => '2026-10-01']);
    BillLine::factory()->forBill($monthly)->create(['amount_centavos' => 200000]);
    BillLine::factory()->forBill($monthly)->create(['kind' => BillLineKind::FixedUtility, 'amount_centavos' => 30000]);
    $charge = Bill::factory()->forTenancy($this->kimStay)->create(['kind' => BillKind::Charge, 'issued_on' => '2026-10-10', 'due_on' => '2026-10-15']);
    BillLine::factory()->forBill($charge)->create(['kind' => BillLineKind::OneTime, 'amount_centavos' => 50000]);
    BillLine::factory()->forBill($charge)->create(['kind' => BillLineKind::Adjustment, 'amount_centavos' => -10000]);
    $share = Bill::factory()->forTenancy($this->kimStay)->create(['kind' => BillKind::Utility, 'issued_on' => '2026-10-12', 'due_on' => '2026-10-19']);
    BillLine::factory()->forBill($share)->create(['kind' => BillLineKind::UtilityShare, 'amount_centavos' => 40000]);

    // Payments: ₱2,000 cash; ₱300 from a deposit (not new money); reversed and pending ones never count.
    Payment::factory()->forPayer($this->house, $this->kim)->confirmed()->create(['amount_centavos' => 200000, 'paid_on' => '2026-10-03']);
    Payment::factory()->forPayer($this->house, $this->kim)->confirmed()->create(['amount_centavos' => 30000, 'paid_on' => '2026-10-18', 'method' => PaymentMethod::Deposit]);
    Payment::factory()->forPayer($this->house, $this->kim)->create(['amount_centavos' => 90000, 'paid_on' => '2026-10-04', 'status' => PaymentStatus::Reversed]);
    Payment::factory()->forPayer($this->house, $this->kim)->create(['amount_centavos' => 70000, 'paid_on' => '2026-10-19']);
    app(PaymentLedger::class)->settle($this->house->id, $this->kim->id);

    MaintenanceRequest::factory()->forTenancy($this->kimStay)->create(['repair_cost_centavos' => 15000, 'resolved_at' => '2026-10-16 09:00']);

    // The annex: ₱1,000 paid ahead with nothing billed yet, so it is credit.
    $ben = User::factory()->boarder()->create();
    Tenancy::factory()->forUnit($this->annex->units()->first())->movedInOn('2026-08-01')->create(['tenant_id' => $ben->id]);
    Payment::factory()->forPayer($this->annex, $ben)->confirmed()->create(['amount_centavos' => 100000, 'paid_on' => '2026-10-02']);
});

afterEach(fn () => Date::setTestNow());

it('adds up a property by the agreed rules', function () {
    $data = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/finance?property_id={$this->house->id}")->assertOk()->json('data');
    $t = $data['totals'];

    expect($data['period'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->and($t['billed'])->toBe(['rent' => 400000, 'fixed_utilities' => 30000, 'utility_shares' => 40000, 'charges' => 50000, 'corrections' => -10000, 'total' => 510000])
        ->and($t['collected'])->toBe(['payments' => 200000, 'first_rent' => 200000, 'total' => 400000, 'unapplied' => 0])
        ->and($t['paid_from_deposit'])->toBe(30000)
        ->and($t['collection'])->toBe(['due' => 320000, 'paid' => 240000, 'rate' => 0.75])
        ->and($t['outstanding']['total'])->toBe(80000)
        ->and($t['outstanding']['overdue'])->toBe(80000)
        ->and($t['outstanding']['ageing']['days_1_30'])->toBe(80000)
        ->and($t['credit_held'])->toBe(0)
        ->and($t['deposits'])->toBe(['received' => 400000, 'held' => 800000, 'refunded' => 250000, 'kept' => 50000])
        ->and($t['utilities'])->toBe(['billed' => 70000, 'provider_cost' => 0, 'waived' => 0])
        ->and($t['repair_costs'])->toBe(15000)
        ->and($t['occupancy'])->toBe(['places' => 2, 'occupied' => 1]);
});

it('totals every property, lists each, and shows the trend', function () {
    $data = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance')->assertOk()->json('data');

    expect(collect($data['properties'])->pluck('name')->all())->toBe(['Annex', 'Santos House'])
        ->and($data['totals']['collected'])->toBe(['payments' => 300000, 'first_rent' => 200000, 'total' => 500000, 'unapplied' => 100000])
        ->and($data['totals']['credit_held'])->toBe(100000)
        ->and($data['by_property'][0]['property']['name'])->toBe('Annex')
        ->and($data['by_property'][0]['collection']['rate'])->toBeNull()   // nothing was due
        ->and($data['series'])->toHaveCount(12)
        ->and(end($data['series']))->toBe(['month' => '2026-10', 'billed' => 510000, 'collected' => 500000])
        ->and($data['series'][10])->toBe(['month' => '2026-09', 'billed' => 200000, 'collected' => 200000]);   // Kim's first rent

    // A period: September only.
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance?from=2026-09-01&to=2026-09-30')->assertOk()
        ->assertJsonPath('data.totals.collected.first_rent', 200000)
        ->assertJsonPath('data.totals.deposits.received', 400000);
});

it('splits a property into rooms where the money was applied', function () {
    $data = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/finance")->assertOk()->json('data');

    expect($data['rooms'])->toHaveCount(1)
        ->and($data['rooms'][0]['room']['id'])->toBe($this->kimStay->room_id)
        ->and($data['rooms'][0]['billed']['total'])->toBe(510000)
        ->and($data['rooms'][0]['collected'])->toBe(['payments' => 200000, 'first_rent' => 200000, 'total' => 400000, 'unapplied' => null])
        ->and($data['rooms'][0]['credit_held'])->toBeNull()
        ->and($data['rooms'][0]['outstanding']['total'])->toBe(80000)
        ->and($data['totals']['collected']['total'])->toBe(400000);
});

it('shows finances to the owner and Managers only', function () {
    $this->actingAs($this->collector, 'sanctum')->getJson('/api/v1/finance')->assertForbidden();
    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/finance")->assertForbidden();
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/finance")->assertNotFound();

    // A Manager sees only the properties they are assigned to.
    $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/finance')->assertOk()->assertJsonPath('data.properties', [['id' => $this->house->id, 'name' => 'Santos House']]);
    $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/finance?property_id={$this->annex->id}")->assertUnprocessable()->assertJsonValidationErrors(['property_id']);
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance?from=2026-10-10')->assertUnprocessable()->assertJsonValidationErrors(['to']);
});

it('gives the same collected and overdue amounts as the dashboard and the CSV', function () {
    $finance = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/finance?property_id={$this->house->id}")->json('data.totals');
    $dashboard = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/dashboard")->json('data');
    $url = $this->actingAs($this->owner, 'sanctum')
        ->getJson("/api/v1/reports/collections/link?property_id={$this->house->id}&from=2026-10-01&to=2026-10-31")->json('data.url');
    auth()->forgetGuards();
    $rows = array_map('str_getcsv', array_filter(explode("\n", substr($this->get($url)->assertOk()->streamedContent(), 3))));

    expect($dashboard['collected_this_month'])->toBe($finance['collected']['total'])
        ->and($dashboard['overdue']['centavos'])->toBe($finance['outstanding']['overdue'])
        ->and(collect(array_slice($rows, 1))->sum(fn ($r) => (int) round((float) $r[7] * 100)))->toBe($finance['collected']['total'])
        ->and(array_column(array_slice($rows, 1), 0))->toBe(['2026-10-03', '2026-10-05'])   // the cash payment, then Lea's first rent
        ->and($rows[2][5])->toBe('First rent (advance) · Cash');
});

it('splits a room into its stays', function () {
    $data = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/rooms/{$this->kimStay->room_id}/finance")->assertOk()->json('data');

    // Ana moved out before October, so only Lea (newest first) and Kim.
    expect($data['totals']['billed']['total'])->toBe(510000)
        ->and($data['stays'])->toHaveCount(2)
        ->and($data['stays'][1]['stay']['tenant']['name'])->toBe('Kim')
        ->and($data['stays'][0])->toMatchArray(['billed' => 200000, 'paid' => 200000, 'balance' => 0, 'deposit_held' => 400000])
        ->and($data['stays'][1])->toMatchArray(['billed' => 510000, 'paid' => 400000, 'paid_from_deposit' => 30000, 'balance' => 80000, 'overdue' => 80000, 'deposit_held' => 400000])
        ->and(end($data['series']))->toBe(['month' => '2026-10', 'billed' => 510000, 'collected' => 400000])
        ->and($data['series'][10])->toBe(['month' => '2026-09', 'billed' => 200000, 'collected' => 200000]);
});

it('shows one stay from move-in to today', function () {
    $data = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/tenancies/{$this->kimStay->id}/finance")->assertOk()->json('data');

    expect($data['stay']['tenant']['name'])->toBe('Kim')
        ->and($data['totals'])->toMatchArray(['billed' => 510000, 'paid' => 400000, 'balance' => 80000])
        ->and(collect($data['move_in'])->pluck('kind')->sort()->values()->all())->toBe(['deposit', 'first_rent'])
        ->and(collect($data['bills'])->pluck('balance_centavos')->sum())->toBe(80000)
        ->and(collect($data['payments'])->pluck('applied_centavos')->all())->toBe([200000, 30000])   // reversed and pending ones are not here
        ->and($data['settlement'])->toBeNull();

    $ana = Tenancy::where('status', TenancyStatus::Settled)->first();
    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/tenancies/{$ana->id}/finance")->assertOk()
        ->assertJsonPath('data.settlement.refund_centavos', 250000)
        ->assertJsonPath('data.totals.deposit_held', 0);
});

it('keeps room and stay finances to the owner and Managers', function () {
    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/rooms/{$this->kimStay->room_id}/finance")->assertForbidden();
    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/tenancies/{$this->kimStay->id}/finance/pdf-link")->assertForbidden();
    $this->actingAs($this->kim, 'sanctum')->getJson("/api/v1/tenancies/{$this->kimStay->id}/finance")->assertNotFound();
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson("/api/v1/rooms/{$this->kimStay->room_id}/finance/pdf-link")->assertNotFound();
});

it('makes PDF statements with charts for every view', function () {
    $links = [
        '/api/v1/finance/pdf-link',
        "/api/v1/properties/{$this->house->id}/finance/pdf-link?from=2026-09-01&to=2026-10-31",
        "/api/v1/rooms/{$this->kimStay->room_id}/finance/pdf-link",
        "/api/v1/tenancies/{$this->kimStay->id}/finance/pdf-link",
    ];

    foreach ($links as $link) {
        $url = $this->actingAs($this->owner, 'sanctum')->getJson($link)->assertOk()->json('data.url');
        auth()->forgetGuards();
        $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
    }

    // The link alone is the key: changed or unsigned, it fails.
    $this->get('/api/v1/documents/finance?properties='.$this->house->id)->assertForbidden();
});

it('compares with the period just before', function () {
    // October against September: Kim's first rent and deposit came in on Sep 1; nothing was due.
    $september = fn (?int $providerCost) => ['period' => ['from' => '2026-09-01', 'to' => '2026-09-30'], 'billed' => 200000, 'collected' => 200000,
        'collection_rate' => null, 'deposits_received' => 400000, 'utilities_billed' => 0, 'provider_cost' => $providerCost, 'repair_costs' => 0];

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance')->assertOk()
        ->assertJsonPath('data.previous', $september(0));
    $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/finance")->assertOk()
        ->assertJsonPath('data.previous', $september(0));
    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/rooms/{$this->kimStay->room_id}/finance")->assertOk()
        ->assertJsonPath('data.previous', $september(null));   // provider bills are per property

    // Days that are not whole months compare with as many days before.
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance?from=2026-10-05&to=2026-10-18')->assertOk()
        ->assertJsonPath('data.previous.period', ['from' => '2026-09-21', 'to' => '2026-10-04'])
        ->assertJsonPath('data.previous.collected', 300000);   // the October cash payment and the annex's ₱1,000
});

it('picks whole months or as many days for the period before', function (string $from, string $to, array $expected) {
    [$prevFrom, $prevTo] = app(FinanceOverview::class)->previousPeriod(CarbonImmutable::parse($from), CarbonImmutable::parse($to));

    expect([$prevFrom->toDateString(), $prevTo->toDateString()])->toBe($expected);
})->with([
    'one month' => ['2026-10-01', '2026-10-31', ['2026-09-01', '2026-09-30']],
    'a quarter' => ['2026-07-01', '2026-09-30', ['2026-04-01', '2026-06-30']],
    'March into February' => ['2026-03-01', '2026-03-31', ['2026-02-01', '2026-02-28']],
    'a year' => ['2026-01-01', '2026-12-31', ['2025-01-01', '2025-12-31']],
    'fourteen days' => ['2026-10-05', '2026-10-18', ['2026-09-21', '2026-10-04']],
    'one day' => ['2026-10-20', '2026-10-20', ['2026-10-19', '2026-10-19']],
]);

it('exports income by month and money by room as CSV', function () {
    $csv = function (string $report, string $query) {
        $url = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/reports/{$report}/link?property_id={$this->house->id}&{$query}")->assertOk()->json('data.url');
        auth()->forgetGuards();

        return array_map('str_getcsv', array_filter(explode("\n", substr($this->get($url)->assertOk()->streamedContent(), 3))));
    };

    $months = $csv('income_by_month', 'from=2026-09-01&to=2026-10-31');
    expect(array_column(array_slice($months, 1), 1))->toBe(['2026-09', '2026-10'])
        ->and($months[2][7])->toBe('5100.00')     // billed in October
        ->and($months[2][10])->toBe('4000.00')    // collected, as on the finance screen
        ->and($months[2][11])->toBe('300.00')     // from a deposit, not counted as collected
        ->and($months[1][10])->toBe('2000.00');   // Kim's first rent in September

    $rooms = $csv('room_finance', 'from=2026-10-01&to=2026-10-31');
    expect($rooms)->toHaveCount(2)
        ->and($rooms[1][5])->toBe('5100.00')
        ->and($rooms[1][6])->toBe('4000.00')
        ->and($rooms[1][10])->toBe('75.0')
        ->and($rooms[1][11])->toBe('800.00');
});

it('rebuilds places taken month by month from move-ins and move-outs', function () {
    $series = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/finance")
        ->assertOk()->json('data.occupancy_series');
    $month = fn (string $m) => collect($series)->firstWhere('month', $m);

    // Both beds were added today; before that a bed counts only in a month someone stayed in it.
    expect($series)->toHaveCount(12)
        ->and($month('2026-05'))->toBe(['month' => '2026-05', 'places' => 0, 'occupied' => 0, 'rate' => null])
        ->and($month('2026-06'))->toBe(['month' => '2026-06', 'places' => 1, 'occupied' => 1, 'rate' => 1])
        // Ana moved out on 30 September, so only Kim is in at the end of the month.
        ->and($month('2026-09'))->toBe(['month' => '2026-09', 'places' => 2, 'occupied' => 1, 'rate' => 0.5])
        ->and($month('2026-10'))->toBe(['month' => '2026-10', 'places' => 2, 'occupied' => 2, 'rate' => 1]);

    // Every property, and one room, get the same series.
    $all = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance')->assertOk()->json('data.occupancy_series');
    expect(end($all))->toBe(['month' => '2026-10', 'places' => 3, 'occupied' => 3, 'rate' => 1]);
    $room = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/rooms/{$this->kimStay->room_id}/finance")->assertOk()->json('data.occupancy_series');
    expect(end($room)['occupied'])->toBe(2);
});

it('counts a whole-room stay as all the room places and leaves out removed places', function () {
    $dorm = makeProperty($this->owner, ['details' => ['name' => 'Dorm'], 'units' => ['count' => 3, 'rent_centavos' => 200000]]);
    $room = $dorm->rooms()->first();
    $whole = app(PropertySetup::class)->offerWhole($room, 500000, null, $this->owner);
    Tenancy::factory()->forUnit($whole)->movedInOn('2026-10-01')->create();
    $whole->forceFill(['status' => UnitStatus::Occupied])->save();
    $dorm->units()->where('is_whole_option', false)->latest('id')->first()->delete();
    UnitStatusService::syncWholeOption($room->id);

    $finance = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$dorm->id}/finance")->assertOk()->json('data');

    expect(end($finance['occupancy_series']))->toBe(['month' => '2026-10', 'places' => 2, 'occupied' => 2, 'rate' => 1])
        // Today's figure and the dashboard agree: the blocked bedspaces are taken, not free.
        ->and($finance['totals']['occupancy'])->toBe(['places' => 2, 'occupied' => 2])
        ->and($finance['rooms'][0]['occupancy'])->toBe(['places' => 2, 'occupied' => 2]);
    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$dorm->id}/dashboard")->assertOk()
        ->assertJsonPath('data.occupancy.places', 2)
        ->assertJsonPath('data.occupancy.occupied', 2)
        ->assertJsonPath('data.occupancy.available', 0);
});
