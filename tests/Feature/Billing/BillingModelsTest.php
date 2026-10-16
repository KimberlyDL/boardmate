<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\FilePurpose;
use App\Enums\PaymentStatus;
use App\Enums\ReminderKind;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillReminder;
use App\Models\CollectionNote;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\UtilityBill;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->unit = $this->property->units()->first();
    $this->tenancy = Tenancy::factory()->forUnit($this->unit)->create();
});

it('keeps a bill with its lines, and totals them with adjustments', function () {
    $bill = Bill::factory()->forTenancy($this->tenancy)->create(['period_start_on' => '2026-10-26', 'period_end_on' => '2026-11-25']);
    $rent = BillLine::factory()->forBill($bill)->create(['amount_centavos' => 180000]);
    BillLine::factory()->forBill($bill)->create(['kind' => BillLineKind::FixedUtility, 'description' => 'Water', 'amount_centavos' => 30000]);
    BillLine::factory()->forBill($bill)->create([
        'kind' => BillLineKind::Adjustment, 'description' => 'Rent corrected', 'amount_centavos' => -10000, 'adjusts_line_id' => $rent->id,
    ]);

    $bill->refresh();
    expect($bill->kind)->toBe(BillKind::Periodic)
        ->and($bill->payer->id)->toBe($this->tenancy->tenant_id)
        ->and($bill->lines)->toHaveCount(3)
        ->and($bill->totalCentavos())->toBe(200000)
        ->and($bill->lines[2]->isCredit())->toBeTrue()
        ->and($bill->lines[2]->adjusts->id)->toBe($rent->id)
        ->and($this->tenancy->bills()->count())->toBe(1);
});

it('allows only one anniversary bill per tenancy period', function () {
    Bill::factory()->forTenancy($this->tenancy)->create(['period_start_on' => '2026-10-26']);

    expect(fn () => Bill::factory()->forTenancy($this->tenancy)->create(['period_start_on' => '2026-10-26']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('tracks how much of a confirmed payment is applied and how much is left as credit', function () {
    $bill = Bill::factory()->forTenancy($this->tenancy)->create();
    $line = BillLine::factory()->forBill($bill)->create(['amount_centavos' => 180000]);
    $payment = Payment::factory()->forPayer($this->property, $this->tenancy->tenant)->confirmed()->create(['amount_centavos' => 200000]);
    PaymentAllocation::create(['bill_line_id' => $line->id, 'payment_id' => $payment->id, 'amount_centavos' => 180000]);

    expect($payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($payment->unallocatedCentavos())->toBe(20000)
        ->and((int) $line->allocations()->sum('amount_centavos'))->toBe(180000)
        ->and($this->property->payments()->count())->toBe(1);
});

it('links a metered bill to its account, and an account to one room or the whole property', function () {
    $room = $this->property->rooms()->first();
    $whole = $this->property->utilityAccounts()->create(['type' => 'electricity', 'name' => 'Meralco', 'method' => 'actual_bill', 'billed_by' => 'owner']);
    $roomMeter = $this->property->utilityAccounts()->create(['room_id' => $room->id, 'type' => 'electricity', 'name' => 'Room meter', 'method' => 'actual_bill', 'billed_by' => 'owner']);
    $utilityBill = UtilityBill::factory()->forAccount($whole)->create();

    expect($whole->room)->toBeNull()
        ->and($roomMeter->room->id)->toBe($room->id)
        ->and($utilityBill->account->id)->toBe($whole->id)
        ->and($whole->utilityBills()->count())->toBe(1)
        ->and($this->property->utilityBills()->count())->toBe(1);
});

it('keeps payment proofs and utility bill photos private, as sent', function () {
    foreach ([FilePurpose::PaymentProof, FilePurpose::UtilityBillPhoto] as $purpose) {
        expect($purpose->isPublic())->toBeFalse()
            ->and($purpose->isImage())->toBeFalse()
            ->and($purpose->allowedExtensions())->toContain('pdf');
    }
});

it('marks a bill late with its notice, puts one under review, and logs its reminders once a day per kind (C7)', function () {
    $bill = Bill::factory()->forTenancy($this->tenancy)->create();
    expect($bill->isLate())->toBeFalse()->and($bill->isUnderReview())->toBeFalse();

    $bill->update(['late_at' => now(), 'late_notice_number' => 'NTC-2026-000001', 'under_review_at' => now(), 'under_review_by' => $this->owner->id, 'under_review_reason' => 'Boarder says she paid']);
    BillReminder::factory()->forBill($bill)->create(['kind' => ReminderKind::DueSoon, 'sent_on' => '2026-10-22']);
    BillReminder::factory()->forBill($bill)->create(['kind' => ReminderKind::Overdue, 'sent_on' => '2026-10-26']);
    BillReminder::factory()->forBill($bill)->create(['kind' => ReminderKind::Overdue, 'sent_on' => '2026-10-28']);

    $bill->refresh();
    expect($bill->isLate())->toBeTrue()
        ->and($bill->isUnderReview())->toBeTrue()
        ->and($bill->reviewer->id)->toBe($this->owner->id)
        ->and($bill->reminders->pluck('kind')->all())->toBe([ReminderKind::DueSoon, ReminderKind::Overdue, ReminderKind::Overdue])
        ->and($bill->reminders[0]->payer->id)->toBe($this->tenancy->tenant_id);

    expect(fn () => BillReminder::factory()->forBill($bill)->create(['kind' => ReminderKind::Overdue, 'sent_on' => '2026-10-26']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps remarks about a payer who is behind, with an optional promised date', function () {
    CollectionNote::factory()->forPayer($this->property, $this->tenancy->tenant)->create(['promised_on' => '2026-10-30', 'created_by' => $this->owner->id]);

    $note = $this->property->collectionNotes()->sole();
    expect($note->promised_on->toDateString())->toBe('2026-10-30')
        ->and($note->payer->id)->toBe($this->tenancy->tenant_id)
        ->and($note->author->id)->toBe($this->owner->id);
});
