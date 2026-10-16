<?php

use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Billing\StatementBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->owner->ownerProfile->update(['business_name' => 'Santos Rentals']);
    $this->property = makeProperty($this->owner);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim Santos']);
    $this->tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $this->collector, 'collector');

    // Kim's Oct 25 bill: rent ₱1,800 + water ₱300.
    $this->bill = Bill::factory()->forTenancy($this->tenancy)->create(['issued_on' => '2026-10-25', 'due_on' => '2026-10-25', 'period_start_on' => '2026-10-26', 'period_end_on' => '2026-11-25']);
    BillLine::factory()->forBill($this->bill)->create(['kind' => BillLineKind::Rent, 'amount_centavos' => 180000, 'description' => 'Rent']);
    BillLine::factory()->forBill($this->bill)->create(['kind' => BillLineKind::FixedUtility, 'amount_centavos' => 30000, 'description' => 'Water']);
});

afterEach(fn () => Date::setTestNow());

function recordCash(User $by, int $propertyId, int $payerId, int $amount, string $on): int
{
    return test()->actingAs($by, 'sanctum')->postJson("/api/v1/properties/{$propertyId}/payments", [
        'payer_id' => $payerId, 'amount_centavos' => $amount, 'method' => 'cash', 'paid_on' => $on,
    ])->assertCreated()->json('data.id');
}

it('builds a statement by date whose closing balance matches the ledger', function () {
    $first = recordCash($this->owner, $this->property->id, $this->kim->id, 50000, '2026-10-25');
    Date::setTestNow(CarbonImmutable::parse('2026-10-27 10:00', 'Asia/Manila'));
    recordCash($this->owner, $this->property->id, $this->kim->id, 150000, '2026-10-27');
    $this->postJson("/api/v1/bills/{$this->bill->id}/adjustments", ['amount_centavos' => -20000, 'reason' => 'Two days without water'])->assertOk();
    Date::setTestNow(CarbonImmutable::parse('2026-10-28 10:00', 'Asia/Manila'));
    $this->postJson("/api/v1/payments/{$first}/reverse", ['reason' => 'Counted twice'])->assertOk();

    $s = app(StatementBuilder::class)->build($this->property->id, $this->kim->id);

    expect(collect($s['entries'])->map(fn ($e) => [$e['date'], $e['kind'], $e['amount_centavos'], $e['balance_centavos']])->all())->toBe([
        ['2026-10-25', 'bill', 210000, 210000],
        ['2026-10-25', 'payment', -50000, 160000],
        ['2026-10-27', 'correction', -20000, 140000],
        ['2026-10-27', 'payment', -150000, -10000],
        ['2026-10-28', 'reversal', 50000, 40000],
    ])
        ->and($s['from'])->toBe('2026-10-25')
        ->and($s['closing_centavos'])->toBe(40000)
        ->and($s['charges_centavos'])->toBe(260000)
        ->and($s['payments_centavos'])->toBe(220000);

    $ledger = app(PaymentLedger::class)->summary($this->property->id, $this->kim->id);
    expect($ledger['owed_centavos'] - $ledger['credit_centavos'])->toBe($s['closing_centavos']);

    // A later period opens with what came before.
    $later = app(StatementBuilder::class)->build($this->property->id, $this->kim->id, '2026-10-27', '2026-10-28');
    expect($later['opening_centavos'])->toBe(160000)->and($later['entries'])->toHaveCount(3);
});

it('gives the payer and staff a receipt link that opens a PDF without a token, and nobody else', function () {
    $paymentId = recordCash($this->collector, $this->property->id, $this->kim->id, 210000, '2026-10-25');

    foreach ([$this->kim, $this->collector, $this->owner] as $user) {
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/payments/{$paymentId}/receipt-link")->assertOk()
            ->assertJsonPath('data.expires_in_minutes', 30);
    }
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson("/api/v1/payments/{$paymentId}/receipt-link")->assertNotFound();

    $url = $this->actingAs($this->kim, 'sanctum')->getJson("/api/v1/payments/{$paymentId}/receipt-link")->json('data.url');
    app('auth')->forgetGuards();
    $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Content-Disposition'))->toContain('OR-2026-000001.pdf')
        ->and(substr($pdf->getContent(), 0, 5))->toBe('%PDF-')
        ->and($pdf->getContent())->toContain('DejaVuSans'); // the font with the ₱ sign is embedded

    $this->get($url.'x')->assertForbidden(); // tampered
    Date::setTestNow(now()->addMinutes(31));
    $this->get($url)->assertForbidden(); // expired
});

it('has receipts only for confirmed or reversed payments, and keeps a reversed one with a stamp', function () {
    $pending = $this->actingAs($this->kim, 'sanctum')->postJson('/api/v1/me/payments', [
        'property_id' => $this->property->id, 'amount_centavos' => 1000, 'method' => 'e_wallet', 'reference' => 'GC-1', 'paid_on' => '2026-10-25',
    ])->assertCreated()->json('data.id');
    $this->getJson("/api/v1/payments/{$pending}/receipt-link")->assertNotFound();

    $paid = recordCash($this->owner, $this->property->id, $this->kim->id, 210000, '2026-10-25');
    $this->postJson("/api/v1/payments/{$paid}/reverse", ['reason' => 'Wrong boarder'])->assertOk();
    $url = $this->getJson("/api/v1/payments/{$paid}/receipt-link")->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('has a notice only for a bill marked late, and statements for staff and for the payer', function () {
    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/bills/{$this->bill->id}/notice-link")->assertNotFound();

    Date::setTestNow(CarbonImmutable::parse('2026-10-29 10:00', 'Asia/Manila'));
    $this->postJson("/api/v1/bills/{$this->bill->id}/mark-late")->assertOk();
    $url = $this->actingAs($this->kim, 'sanctum')->getJson("/api/v1/bills/{$this->bill->id}/notice-link")->assertOk()->json('data.url');
    $notice = $this->get($url)->assertOk();
    expect($notice->headers->get('Content-Disposition'))->toContain('NTC-2026-000001.pdf');

    $staff = $this->actingAs($this->collector, 'sanctum')
        ->getJson("/api/v1/properties/{$this->property->id}/payers/{$this->kim->id}/statement-link?from=2026-10-01")->assertOk()->json('data.url');
    $this->get($staff)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->getJson("/api/v1/properties/{$this->property->id}/payers/{$this->kim->id}/statement-link?from=2026-11-01")
        ->assertUnprocessable()->assertJsonValidationErrors(['from']);

    $mine = $this->actingAs($this->kim, 'sanctum')->getJson("/api/v1/me/statement-link?property_id={$this->property->id}")->assertOk()->json('data.url');
    $this->get($mine)->assertOk();
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson("/api/v1/me/statement-link?property_id={$this->property->id}")
        ->assertUnprocessable()->assertJsonValidationErrors(['property_id']);
});
