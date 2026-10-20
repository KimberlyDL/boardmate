<?php

use App\Enums\BillKind;
use App\Enums\CaretakerAccessLevel;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Payment;
use App\Models\RoomLeader;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    $this->property = makeProperty($this->owner, ['details' => ['name' => 'Santos House'], 'units' => ['count' => 2, 'rent_centavos' => 200000]]);
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->stay = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

/** Ask for a link as $user and download it: the CSV as rows (header first). */
function csvReport(User $user, string $report, array $query = []): array
{
    $url = test()->actingAs($user, 'sanctum')->getJson("/api/v1/reports/{$report}/link?".http_build_query($query))->assertOk()->json('data.url');
    auth()->forgetGuards();
    $response = test()->get($url)->assertOk();
    $body = $response->streamedContent();
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF"); // so Excel reads ₱ and ñ

    return array_map('str_getcsv', array_filter(explode("\n", substr($body, 3))));
}

it('lists the reports each person may download', function () {
    $keys = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/reports')->assertOk()->json('data'))->pluck('key')->all();

    expect($keys($this->owner))->toBe(['collections', 'outstanding', 'deposits', 'utilities', 'prices', 'occupancy', 'move_outs', 'repairs', 'visitors', 'curfew'])
        ->and($keys($this->collector))->toBe(['collections', 'outstanding']) // their own work only
        ->and($keys(User::factory()->boarder()->create()))->toBe([]);

    $this->actingAs($this->collector, 'sanctum')->getJson('/api/v1/reports/deposits/link')->assertUnprocessable()->assertJsonValidationErrors(['property_id']);
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson("/api/v1/reports/collections/link?property_id={$this->property->id}")->assertUnprocessable();
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/reports/nope/link')->assertNotFound();
});

it('exports collections in the period, confirmed only, in pesos', function () {
    Payment::factory()->forPayer($this->property, $this->kim)->confirmed()->create(['amount_centavos' => 200000, 'paid_on' => '2026-10-05', 'receipt_number' => 'OR-2026-000001', 'reference' => 'GC-1']);
    Payment::factory()->forPayer($this->property, $this->kim)->create(['amount_centavos' => 50000, 'paid_on' => '2026-10-06']);              // not confirmed
    Payment::factory()->forPayer($this->property, $this->kim)->confirmed()->create(['amount_centavos' => 70000, 'paid_on' => '2026-09-30']); // before the period

    $rows = csvReport($this->collector, 'collections', ['from' => '2026-10-01', 'to' => '2026-10-31']);
    expect($rows[0])->toBe(['Date paid', 'Receipt no.', 'Property', 'Payer', 'Room', 'Method', 'Reference', 'Amount (PHP)', 'Recorded by'])
        ->and($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('2026-10-05')
        ->and($rows[1][1])->toBe('OR-2026-000001')
        ->and($rows[1][3])->toBe('Kim')
        ->and($rows[1][7])->toBe('2000.00');

    // No dates: this month by default; the link names the file.
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/reports/collections/link')->assertOk()
        ->assertJsonPath('data.filename', 'dormlink-collections-santos-house-2026-10-01_to_2026-10-31.csv');
});

it('exports outstanding balances with their ageing as of today', function () {
    foreach ([['2026-10-25', 200000], ['2026-10-05', 50000], ['2026-07-01', 30000]] as [$due, $amount]) {
        $bill = Bill::factory()->forTenancy($this->stay)->create(['kind' => BillKind::Periodic, 'issued_on' => '2026-06-25', 'due_on' => $due]);
        BillLine::factory()->forBill($bill)->create(['amount_centavos' => $amount]);
    }

    $rows = csvReport($this->owner, 'outstanding');
    expect(array_map(fn ($r) => [$r[6], $r[7], $r[8], $r[9]], array_slice($rows, 1)))->toBe([
        ['2026-07-01', '111', 'Over 90 days', '300.00'],
        ['2026-10-05', '15', '1–30 days', '500.00'],
        ['2026-10-25', '0', 'Not yet due', '2000.00'],
    ]);
});

it('exports each stay\'s deposit and its settlement', function () {
    TenancyPayment::factory()->create(['tenancy_id' => $this->stay->id, 'amount_centavos' => 200000, 'received_on' => '2026-09-01']);
    $this->stay->update(['status' => 'settled', 'moved_out_on' => '2026-10-15']);
    Settlement::factory()->forTenancy($this->stay)->create(['deposit_held_centavos' => 200000, 'deductions_centavos' => 30000, 'applied_centavos' => 20000, 'refund_centavos' => 150000, 'kept_centavos' => 0]);

    $rows = csvReport($this->owner, 'deposits');
    expect($rows[1])->toBe(['Santos House', 'Kim', $this->stay->room->label(), '2026-09-01', '2026-10-15', '2000.00', '2026-09-01', '300.00', '200.00', '1500.00', '0.00', '', '0.00', '2026-10-20']);
});

it('exports utility bills with each room\'s share, and price changes', function () {
    RoomLeader::factory()->create(['room_id' => $this->stay->room_id, 'user_id' => $this->kim->id]);
    $meralco = $this->property->utilityAccounts()->create(['type' => 'electricity', 'name' => 'Meralco', 'method' => 'actual_bill', 'billed_by' => 'owner']);
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/utility-bills", [
        'utility_account_id' => $meralco->id, 'coverage_from_on' => '2026-09-10', 'coverage_to_on' => '2026-10-09', 'total_centavos' => 180000,
    ])->assertCreated();

    $rows = csvReport($this->owner, 'utilities');
    expect($rows)->toHaveCount(2)
        ->and(array_slice($rows[1], 0, 5))->toBe(['Santos House', 'Meralco', '2026-09-10', '2026-10-09', '1800.00'])
        ->and($rows[1][9])->toBe('Kim');

    // The rents set when the property was made take effect this month.
    $prices = csvReport($this->owner, 'prices');
    expect(count($prices))->toBeGreaterThan(1)
        ->and($prices[1][1])->toStartWith('Rent · room ')
        ->and($prices[1][2])->toBe('2000.00');
});

it('refuses a link that was changed or has expired', function () {
    $url = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/reports/outstanding/link')->json('data.url');
    $this->app['auth']->forgetGuards();

    $this->get(str_replace('outstanding', 'deposits', $url))->assertForbidden();
    Date::setTestNow(now()->addHour());
    $this->get($url)->assertForbidden();
});
