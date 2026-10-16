<?php

use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-08-25')->create(['tenant_id' => $this->kim->id]);
    $this->url = "/api/v1/properties/{$this->property->id}/payments";
});

afterEach(fn () => Date::setTestNow());

/** A bill to Kim due on $due with [kind, amount] lines. */
function kimBill(Tenancy $tenancy, string $due, array $lines): Bill
{
    $bill = Bill::factory()->forTenancy($tenancy)->create(['issued_on' => $due, 'due_on' => $due]);
    foreach ($lines as $i => [$kind, $amount]) {
        BillLine::factory()->forBill($bill)->create(['kind' => $kind, 'amount_centavos' => $amount, 'description' => $kind->label(), 'sort_order' => $i]);
    }

    return $bill;
}

it('applies a partial payment to the oldest charges, rent before utilities (SC-15)', function () {
    $sept = kimBill($this->tenancy, '2026-09-25', [[BillLineKind::UtilityShare, 50000]]);
    $oct = kimBill($this->tenancy, '2026-10-25', [[BillLineKind::FixedUtility, 50000], [BillLineKind::Rent, 180000]]);

    $this->actingAs($this->owner, 'sanctum')->postJson($this->url, [
        'payer_id' => $this->kim->id, 'amount_centavos' => 200000, 'method' => 'cash', 'paid_on' => '2026-10-26',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.receipt_number', 'OR-2026-000001')
        ->assertJsonPath('data.reported_by_payer', false);

    $rent = $oct->lines()->where('kind', BillLineKind::Rent)->first();
    expect($sept->balanceCentavos())->toBe(0)
        ->and($rent->allocations()->sum('amount_centavos'))->toEqual(150000)
        ->and($oct->balanceCentavos())->toBe(80000);

    $this->getJson("/api/v1/properties/{$this->property->id}/payers/{$this->kim->id}/balance")->assertOk()
        ->assertJsonPath('data.owed_centavos', 80000)
        ->assertJsonPath('data.credit_centavos', 0)
        ->assertJsonCount(1, 'data.bills')
        ->assertJsonPath('data.bills.0.balance_centavos', 80000);

    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::PaymentConfirmed && $n->data['receipt_number'] === 'OR-2026-000001' && $n->data['owed'] === '₱800.00');
});

it('holds an overpayment as credit and applies it when the next charge is issued (SC-34)', function () {
    $this->actingAs($this->owner, 'sanctum')->postJson($this->url, [
        'payer_id' => $this->kim->id, 'amount_centavos' => 400000, 'method' => 'e_wallet', 'reference' => 'GC-123', 'paid_on' => '2026-10-20',
    ])->assertCreated();
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::PaymentConfirmed && $n->data['credit'] === '₱4,000.00');

    $billId = $this->postJson("/api/v1/properties/{$this->property->id}/charges", [
        'tenancy_id' => $this->tenancy->id, 'amount_centavos' => 30000, 'description' => 'Lost key', 'reason' => 'Replacement',
    ])->assertCreated()->assertJsonPath('data.balance_centavos', 0)->json('data.id');

    $this->getJson("/api/v1/properties/{$this->property->id}/payers/{$this->kim->id}/balance")
        ->assertJsonPath('data.owed_centavos', 0)
        ->assertJsonPath('data.credit_centavos', 370000);
    expect(Bill::find($billId)->balanceCentavos())->toBe(0);
});

it('uses a credit line on its own bill first, then on other charges', function () {
    $older = kimBill($this->tenancy, '2026-09-25', [[BillLineKind::Rent, 100000]]);
    $bill = kimBill($this->tenancy, '2026-10-25', [[BillLineKind::Rent, 180000]]);

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/bills/{$bill->id}/adjustments", ['amount_centavos' => -20000, 'reason' => 'No water'])
        ->assertOk()->assertJsonPath('data.balance_centavos', 160000);

    expect($older->balanceCentavos())->toBe(100000);
});

it('takes a boarder report, flags a reused reference, and confirms or rejects it', function () {
    Storage::fake('local');
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $collector, 'collector');
    $bill = kimBill($this->tenancy, '2026-10-25', [[BillLineKind::Rent, 180000]]);
    $body = ['property_id' => $this->property->id, 'amount_centavos' => 180000, 'method' => 'e_wallet', 'paid_on' => '2026-10-25'];

    $this->actingAs($this->kim, 'sanctum')->postJson('/api/v1/me/payments', $body)
        ->assertUnprocessable()->assertJsonValidationErrors(['reference']);
    $first = $this->postJson('/api/v1/me/payments', $body + ['reference' => 'GC-777'])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.receipt_number', null)
        ->assertJsonPath('data.reported_by_payer', true)
        ->json('data.id');
    $second = $this->post('/api/v1/me/payments', $body + ['reference' => 'gc-777 ', 'proof' => UploadedFile::fake()->image('gcash.png')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    expect($second['proof_url'])->toContain('/api/v1/files/payment-proofs/');

    Notification::assertSentTo([$this->owner, $collector], DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::PaymentReported && $n->data['payer_name'] === 'Kim');
    expect($bill->balanceCentavos())->toBe(180000); // pending money pays nothing

    $this->actingAs($collector, 'sanctum')->getJson("{$this->url}?status=pending")->assertOk()
        ->assertJsonPath('meta.pending_count', 2)
        ->assertJsonPath('data.0.reference_reused', true)
        ->assertJsonPath('data.1.reference_reused', true);

    $this->postJson("/api/v1/payments/{$first}/confirm")->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.receipt_number', 'OR-2026-000001')
        ->assertJsonPath('data.decided_by', $collector->name);
    expect($bill->balanceCentavos())->toBe(0);

    $this->postJson("/api/v1/payments/{$second['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/payments/{$second['id']}/reject", ['reason' => 'Same screenshot as before'])->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.reject_reason', 'Same screenshot as before');
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::PaymentRejected && $n->data['reason'] === 'Same screenshot as before');

    // Decided once only.
    $this->postJson("/api/v1/payments/{$first}/reject", ['reason' => 'X'])->assertUnprocessable();
    $this->postJson("/api/v1/payments/{$second['id']}/confirm")->assertUnprocessable();

    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/payments')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.property_name', 'Santos Boarding House')
        ->assertJsonMissingPath('data.0.reference_reused');
});

it('reverses a mistaken payment without deleting it (owner or Manager only)', function () {
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    assignCaretaker($this->property, $collector, 'collector');
    assignCaretaker($this->property, $manager, 'manager');
    $bill = kimBill($this->tenancy, '2026-10-25', [[BillLineKind::Rent, 180000]]);

    $id = $this->actingAs($collector, 'sanctum')->postJson($this->url, [
        'payer_id' => $this->kim->id, 'amount_centavos' => 180000, 'method' => 'cash', 'paid_on' => '2026-10-26',
    ])->assertCreated()->json('data.id');
    expect($bill->balanceCentavos())->toBe(0);

    $this->postJson("/api/v1/payments/{$id}/reverse", ['reason' => 'Wrong boarder'])->assertForbidden();
    $this->actingAs($manager, 'sanctum')->postJson("/api/v1/payments/{$id}/reverse")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/payments/{$id}/reverse", ['reason' => 'Wrong boarder'])->assertOk()
        ->assertJsonPath('data.status', 'reversed')
        ->assertJsonPath('data.receipt_number', 'OR-2026-000001')
        ->assertJsonPath('data.reversal.reason', 'Wrong boarder')
        ->assertJsonPath('data.reversal.by', $manager->name);
    $this->postJson("/api/v1/payments/{$id}/reverse", ['reason' => 'Again'])->assertUnprocessable();

    expect($bill->balanceCentavos())->toBe(180000)
        ->and(Payment::find($id)->status)->toBe(PaymentStatus::Reversed);
    Notification::assertSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::PaymentReversed);

    // The reversal entry is not listed as a payment of its own.
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
});

it('only records payments for people who stay or stayed here, and keeps owners apart', function () {
    $stranger = User::factory()->boarder()->create();
    $body = ['payer_id' => $stranger->id, 'amount_centavos' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-26'];

    $this->actingAs($this->owner, 'sanctum')->postJson($this->url, $body)
        ->assertUnprocessable()->assertJsonValidationErrors(['payer_id']);
    $this->postJson($this->url, ['payer_id' => $this->kim->id, 'paid_on' => '2026-10-27'] + $body)
        ->assertUnprocessable()->assertJsonValidationErrors(['paid_on']);

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson($this->url)->assertNotFound();
    $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/me/payments', [
        'property_id' => $this->property->id, 'amount_centavos' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-26', 'reference' => 'X',
    ])->assertUnprocessable()->assertJsonValidationErrors(['property_id']);
});

it('lists unpaid bills oldest first with what is left, and paid ones apart', function () {
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $collector, 'collector');
    $sept = kimBill($this->tenancy, '2026-09-25', [[BillLineKind::Rent, 180000]]);
    $oct = kimBill($this->tenancy, '2026-10-25', [[BillLineKind::Rent, 180000]]);
    $nov = kimBill($this->tenancy, '2026-11-25', [[BillLineKind::Rent, 180000]]);

    $this->actingAs($collector, 'sanctum')->postJson($this->url, [
        'payer_id' => $this->kim->id, 'amount_centavos' => 200000, 'method' => 'cash', 'paid_on' => '2026-10-26',
    ])->assertCreated();

    $bills = "/api/v1/properties/{$this->property->id}/bills";
    $this->getJson($bills)->assertOk()
        ->assertJsonPath('meta.unpaid_count', 2)
        ->assertJsonPath('meta.unpaid_total_centavos', 340000)
        ->assertJsonPath('data.0.id', $oct->id)
        ->assertJsonPath('data.0.status', 'partly_paid')
        ->assertJsonPath('data.0.balance_centavos', 160000)
        ->assertJsonPath('data.0.is_overdue', true)
        ->assertJsonPath('data.1.id', $nov->id)
        ->assertJsonPath('data.1.status', 'unpaid')
        ->assertJsonPath('data.1.is_overdue', false);
    $this->getJson("{$bills}?status=paid")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sept->id);
    $this->getJson("{$bills}?status=all")->assertOk()->assertJsonCount(3, 'data');

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson($bills)->assertNotFound();
});

it('shows a boarder their own bills, what they owe each place, and how to pay', function () {
    $this->owner->ownerProfile->update(['gcash_name' => 'Ana Santos', 'gcash_number' => '0917 123 4567']);
    $oct = kimBill($this->tenancy, '2026-10-25', [[BillLineKind::Rent, 180000]]);
    kimBill($this->tenancy, '2026-09-25', [[BillLineKind::Rent, 180000]]);
    $this->actingAs($this->owner, 'sanctum')->postJson($this->url, [
        'payer_id' => $this->kim->id, 'amount_centavos' => 200000, 'method' => 'cash', 'paid_on' => '2026-10-26',
    ])->assertCreated();
    // Someone else's bill at the same place stays out of sight.
    $other = Tenancy::factory()->forUnit($this->property->units()->skip(1)->first())->create();
    kimBill($other, '2026-10-25', [[BillLineKind::Rent, 180000]]);

    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/bills')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $oct->id)
        ->assertJsonPath('data.0.property_name', 'Santos Boarding House')
        ->assertJsonPath('data.0.balance_centavos', 160000)
        ->assertJsonPath('meta.places.0.owed_centavos', 160000)
        ->assertJsonPath('meta.places.0.credit_centavos', 0)
        ->assertJsonPath('meta.places.0.pay_to.gcash_number', '0917 123 4567');
    $this->getJson('/api/v1/me/bills?status=paid')->assertOk()->assertJsonCount(1, 'data');

    // No stay, no places and no payment details.
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson('/api/v1/me/bills')->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonCount(0, 'meta.places');
});
