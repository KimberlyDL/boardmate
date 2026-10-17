<?php

use App\Enums\NoticeStatus;
use App\Enums\PaymentMethod;
use App\Enums\TenancyStatus;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
});

it('has the move-out states, and only Active and Leaving count as current', function () {
    expect(array_map(fn ($s) => $s->value, TenancyStatus::cases()))->toBe(['active', 'notice_given', 'ended', 'settled'])
        ->and(TenancyStatus::NoticeGiven->label())->toBe('Leaving')
        ->and(TenancyStatus::currentValues())->toBe(['active', 'notice_given']);

    $this->tenancy->update(['status' => TenancyStatus::NoticeGiven, 'end_on' => '2026-11-25']);
    expect(Tenancy::current()->count())->toBe(1);
    $this->tenancy->update(['status' => TenancyStatus::Ended, 'moved_out_on' => '2026-11-25', 'moved_out_by' => $this->owner->id]);
    expect(Tenancy::current()->count())->toBe(0)
        ->and($this->tenancy->fresh()->moveOutRecorder->id)->toBe($this->owner->id);
});

it('keeps every notice, finds the pending one, and flags an overstay after the end date', function () {
    TenancyNotice::factory()->forTenancy($this->tenancy)->create(['end_on' => '2026-11-10', 'status' => NoticeStatus::Declined, 'decline_reason' => 'Too soon']);
    $pending = TenancyNotice::factory()->forTenancy($this->tenancy)->create(['end_on' => '2026-11-25', 'is_short_notice' => true]);

    $t = $this->tenancy->fresh();
    expect($t->notices)->toHaveCount(2)
        ->and($t->pendingNotice->id)->toBe($pending->id)
        ->and($t->pendingNotice->is_short_notice)->toBeTrue()
        ->and($t->pendingNotice->filer->id)->toBe($this->kim->id);

    $t->update(['status' => TenancyStatus::NoticeGiven, 'end_on' => '2026-11-25']);
    expect($t->isOverstaying(CarbonImmutable::parse('2026-11-25')))->toBeFalse() // the last day of stay
        ->and($t->isOverstaying(CarbonImmutable::parse('2026-11-26')))->toBeTrue();
});

it('settles a deposit once per tenancy, linked to the deposit applied as a payment', function () {
    $applied = Payment::factory()->forPayer($this->property, $this->kim)->confirmed()->create(['method' => PaymentMethod::Deposit, 'amount_centavos' => 40000]);
    Settlement::factory()->forTenancy($this->tenancy)->create([
        'deposit_held_centavos' => 400000, 'applied_centavos' => 40000, 'refund_centavos' => 360000,
        'payment_id' => $applied->id, 'refund_method' => PaymentMethod::Cash, 'refunded_on' => '2026-11-26', 'settled_by' => $this->owner->id,
    ]);

    $s = $this->tenancy->fresh()->settlement;
    expect($s->payment->method)->toBe(PaymentMethod::Deposit)
        ->and($s->refund_method)->toBe(PaymentMethod::Cash)
        ->and($s->deposit_held_centavos)->toBe($s->applied_centavos + $s->refund_centavos + $s->kept_centavos);

    expect(fn () => Settlement::factory()->forTenancy($this->tenancy)->create())->toThrow(UniqueConstraintViolationException::class);
});

it('never takes "deposit" as a payment method typed in by hand', function () {
    expect(PaymentMethod::manual())->not->toContain(PaymentMethod::Deposit);

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/payments", [
        'payer_id' => $this->kim->id, 'amount_centavos' => 1000, 'method' => 'deposit', 'paid_on' => now()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['method']);
});
