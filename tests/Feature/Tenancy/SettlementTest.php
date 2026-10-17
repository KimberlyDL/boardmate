<?php

use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\PaymentMethod;
use App\Enums\TenancyStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    // Rent is ₱4,500 (from Aug 1); Kim's deposit was ₱4,000 (SC-18: the rent is higher than the deposit).
    Date::setTestNow(CarbonImmutable::parse('2026-08-01 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 450000]]);
    Date::setTestNow(CarbonImmutable::parse('2026-11-27 10:00', 'Asia/Manila'));
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');

    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-08-25')->create([
        'tenant_id' => $this->kim->id, 'status' => TenancyStatus::Ended, 'end_on' => '2026-11-25', 'moved_out_on' => '2026-11-25',
    ]);
    TenancyPayment::factory()->create(['tenancy_id' => $this->tenancy->id, 'amount_centavos' => 400000]);
    $this->t = "/api/v1/tenancies/{$this->tenancy->id}";
});

afterEach(fn () => Date::setTestNow());

/** An unpaid bill to Kim with one line. */
function kimOwes(Tenancy $tenancy, int $amount, string $description, BillLineKind $kind = BillLineKind::Rent): Bill
{
    $bill = Bill::factory()->forTenancy($tenancy)->create(['issued_on' => '2026-10-25', 'due_on' => '2026-10-25']);
    BillLine::factory()->forBill($bill)->create(['kind' => $kind, 'amount_centavos' => $amount, 'description' => $description]);

    return $bill;
}

it('uses the deposit for the last rent: she pays ₱400 more, refund ₱0 (SC-17, apply the deposit)', function () {
    kimOwes($this->tenancy, 400000, 'Rent, Oct 26 – Nov 25');
    kimOwes($this->tenancy, 10000, 'Water', BillLineKind::FixedUtility);

    $this->actingAs($this->manager, 'sanctum')->getJson("{$this->t}/settlement")->assertOk()
        ->assertJsonPath('data.settled', false)
        ->assertJsonPath('data.deposit_held_centavos', 400000)
        ->assertJsonPath('data.owed_centavos', 410000)
        ->assertJsonPath('data.rent_now_centavos', 450000)
        ->assertJsonPath('data.deposit_gap_centavos', 50000)
        ->assertJsonCount(2, 'data.unpaid_bills');

    $this->postJson("{$this->t}/settlement", [
        'deductions' => [['description' => 'Broken chair', 'amount_centavos' => 30000, 'reason' => 'Found at move-out']],
    ])->assertCreated()
        ->assertJsonPath('data.deductions_centavos', 30000)
        ->assertJsonPath('data.deductions.0.description', 'Broken chair')
        ->assertJsonPath('data.applied_centavos', 400000)
        ->assertJsonPath('data.refund_centavos', 0)
        ->assertJsonPath('data.still_owed_centavos', 40000);

    expect($this->tenancy->fresh()->status)->toBe(TenancyStatus::Settled)
        ->and(app(PaymentLedger::class)->summary($this->property->id, $this->kim->id))->toBe(['owed_centavos' => 40000, 'credit_centavos' => 0])
        ->and(Payment::where('method', PaymentMethod::Deposit)->sole()->receipt_number)->toBeNull();
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::DepositSettled && $n->data['still_owed'] === '₱400.00' && $n->data['refund'] === null && count($n->data['deductions']) === 1);
    Notification::assertNotSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::BillIssued);

    // The shortfall stays owed and shows on the overdue list (SC-25).
    $this->getJson("/api/v1/properties/{$this->property->id}/collections")->assertOk()->assertJsonPath('data.0.payer.name', 'Kim');
    $this->postJson("{$this->t}/settlement", [])->assertUnprocessable()->assertJsonPath('errors.tenancy.0', 'This deposit is already settled.');
});

it('refunds the rest when she already paid the rent: refund ₱3,600 (SC-17, keep for refund)', function () {
    kimOwes($this->tenancy, 10000, 'Water', BillLineKind::FixedUtility);

    $this->actingAs($this->owner, 'sanctum')->postJson("{$this->t}/settlement", [
        'deductions' => [['description' => 'Broken chair', 'amount_centavos' => 30000, 'reason' => 'Found at move-out']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['refund_method']);
    expect(Bill::count())->toBe(1); // a refused settlement leaves no deductions behind

    $this->postJson("{$this->t}/settlement", [
        'deductions' => [['description' => 'Broken chair', 'amount_centavos' => 30000, 'reason' => 'Found at move-out']],
        'refund_method' => 'e_wallet', 'refund_reference' => 'GC-9001',
    ])->assertCreated()
        ->assertJsonPath('data.applied_centavos', 40000)
        ->assertJsonPath('data.refund_centavos', 360000)
        ->assertJsonPath('data.refund_method_label', 'E-wallet (GCash, Maya)')
        ->assertJsonPath('data.refunded_on', '2026-11-27')
        ->assertJsonPath('data.still_owed_centavos', 0);
});

it('keeps part of the deposit only with a reason, and returns unused credit with the refund', function () {
    Payment::factory()->forPayer($this->property, $this->kim)->confirmed()->create(['amount_centavos' => 50000, 'paid_on' => '2026-11-20']);
    $this->actingAs($this->owner, 'sanctum');

    $this->postJson("{$this->t}/settlement", ['keep_centavos' => 100000, 'refund_method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors(['keep_reason']);
    $this->postJson("{$this->t}/settlement", ['keep_centavos' => 500000, 'keep_reason' => 'X', 'refund_method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors(['keep_centavos']);
    $this->postJson("{$this->t}/settlement", ['refund_method' => 'deposit'])->assertUnprocessable()->assertJsonValidationErrors(['refund_method']);

    $this->postJson("{$this->t}/settlement", ['keep_centavos' => 100000, 'keep_reason' => 'Left with 3 days\' notice; contract says one month', 'refund_method' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('data.kept_centavos', 100000)
        ->assertJsonPath('data.credit_returned_centavos', 50000)
        ->assertJsonPath('data.refund_centavos', 350000); // 4,000 − 1,000 kept + 500 credit

    expect(app(PaymentLedger::class)->summary($this->property->id, $this->kim->id))->toBe(['owed_centavos' => 0, 'credit_centavos' => 0]);
});

it('settles only after the move-out, by the owner or a Manager; the boarder sees the result and its summary', function () {
    $this->actingAs($this->collector, 'sanctum')->getJson("{$this->t}/settlement")->assertForbidden();
    $this->actingAs($this->kim, 'sanctum')->getJson("{$this->t}/settlement")->assertNotFound();
    $this->getJson("{$this->t}/settlement/summary-link")->assertNotFound();

    $staying = Tenancy::factory()->forUnit($this->property->units()->skip(1)->first())->create();
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/tenancies/{$staying->id}/settlement", [])
        ->assertUnprocessable()->assertJsonPath('errors.tenancy.0', 'Record the move-out first.');

    $this->getJson("/api/v1/properties/{$this->property->id}/tenancies?status=ended")->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("{$this->t}/settlement", ['refund_method' => 'cash'])->assertCreated();
    $this->getJson("/api/v1/properties/{$this->property->id}/tenancies?status=settled")->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($this->kim, 'sanctum')->getJson("{$this->t}/settlement")->assertOk()
        ->assertJsonPath('data.settled', true)->assertJsonPath('data.refund_centavos', 400000);
    $url = $this->getJson("{$this->t}/settlement/summary-link")->assertOk()->json('data.url');
    $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Content-Disposition'))->toContain('settlement-kim.pdf');
});

it('never lets the deposit applied at settlement be reversed like a payment', function () {
    kimOwes($this->tenancy, 10000, 'Water', BillLineKind::FixedUtility);
    $this->actingAs($this->owner, 'sanctum')->postJson("{$this->t}/settlement", ['refund_method' => 'cash'])->assertCreated();

    $applied = Payment::where('method', PaymentMethod::Deposit)->sole();
    $this->postJson("/api/v1/payments/{$applied->id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()->assertJsonValidationErrors(['payment']);
});
