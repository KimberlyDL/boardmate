<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-11 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

it('adds a one-time charge as its own bill, with a reason, due in 7 days by default', function () {
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/charges", [
        'tenancy_id' => $this->tenancy->id, 'amount_centavos' => 30000, 'description' => 'Lost key', 'reason' => 'Replacement key and lock',
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'charge')
        ->assertJsonPath('data.payer.name', 'Kim')
        ->assertJsonPath('data.due_on', '2026-10-18')
        ->assertJsonPath('data.total_centavos', 30000)
        ->assertJsonPath('data.lines.0.kind', 'one_time')
        ->assertJsonPath('data.lines.0.reason', 'Replacement key and lock');

    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::BillIssued && str_contains($n->data['summary'], 'Lost key'));

    // A chosen due date; never in the past, never without a reason, only for someone living there.
    $this->postJson("/api/v1/properties/{$this->property->id}/charges", [
        'tenancy_id' => $this->tenancy->id, 'amount_centavos' => 50000, 'description' => 'Extra occupant', 'reason' => 'Cousin stayed 5 nights', 'due_on' => '2026-10-25',
    ])->assertCreated()->assertJsonPath('data.due_on', '2026-10-25');
    $this->postJson("/api/v1/properties/{$this->property->id}/charges", [
        'tenancy_id' => $this->tenancy->id, 'amount_centavos' => 50000, 'description' => 'Damage', 'due_on' => '2026-10-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'due_on']);
    $elsewhere = Tenancy::factory()->forUnit(makeProperty($this->owner)->units()->first())->create();
    $this->postJson("/api/v1/properties/{$this->property->id}/charges", [
        'tenancy_id' => $elsewhere->id, 'amount_centavos' => 100, 'description' => 'X', 'reason' => 'Y',
    ])->assertUnprocessable()->assertJsonValidationErrors(['tenancy_id']);
});

it('corrects an issued bill with adjustment lines, but never below zero', function () {
    $bill = Bill::factory()->forTenancy($this->tenancy)->create();
    $rent = BillLine::factory()->forBill($bill)->create(['amount_centavos' => 180000, 'description' => 'Rent, Oct 26 – Nov 25, 2026']);

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/bills/{$bill->id}/adjustments", [
        'amount_centavos' => -20000, 'reason' => 'Two days without water', 'line_id' => $rent->id,
    ])->assertOk()
        ->assertJsonPath('data.total_centavos', 160000)
        ->assertJsonPath('data.lines.1.kind', 'adjustment')
        ->assertJsonPath('data.lines.1.description', 'Correction to Rent, Oct 26 – Nov 25, 2026')
        ->assertJsonPath('data.lines.1.adjusts_line_id', $rent->id);
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::BillAdjusted && $n->data['change'] === '-₱200.00' && $n->data['total'] === '₱1,600.00');

    $this->postJson("/api/v1/bills/{$bill->id}/adjustments", ['amount_centavos' => 5000, 'description' => 'Late key return', 'reason' => 'Agreed with Kim'])
        ->assertOk()->assertJsonPath('data.total_centavos', 165000);

    $this->postJson("/api/v1/bills/{$bill->id}/adjustments", ['amount_centavos' => -170000, 'reason' => 'Too much'])
        ->assertUnprocessable()->assertJsonValidationErrors(['amount_centavos']);
    $this->postJson("/api/v1/bills/{$bill->id}/adjustments", ['amount_centavos' => 0, 'reason' => 'Nothing'])
        ->assertUnprocessable()->assertJsonValidationErrors(['amount_centavos']);
    $other = BillLine::factory()->forBill(Bill::factory()->forTenancy($this->tenancy)->create())->create();
    $this->postJson("/api/v1/bills/{$bill->id}/adjustments", ['amount_centavos' => 100, 'reason' => 'X', 'line_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['line_id']);

    expect($bill->lines()->where('kind', BillLineKind::Adjustment)->count())->toBe(2)
        ->and($bill->fresh()->kind)->toBe(BillKind::Periodic);
});

it('lets the owner and Managers add charges and corrections, not Collectors', function () {
    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $manager, 'manager');
    assignCaretaker($this->property, $collector, 'collector');
    $body = ['tenancy_id' => $this->tenancy->id, 'amount_centavos' => 30000, 'description' => 'Lost key', 'reason' => 'Lost'];

    $this->actingAs($collector, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/charges", $body)->assertForbidden();
    $billId = $this->actingAs($manager, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/charges", $body)->assertCreated()->json('data.id');
    $this->actingAs($collector, 'sanctum')->postJson("/api/v1/bills/{$billId}/adjustments", ['amount_centavos' => -100, 'reason' => 'X'])->assertForbidden();
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->postJson("/api/v1/bills/{$billId}/adjustments", ['amount_centavos' => -100, 'reason' => 'X'])->assertNotFound();
});
