<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\ReminderKind;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\ReminderService;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-30 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    [$bed1, $bed2] = $this->property->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim', 'phone' => '0917 000 0001']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $this->kimStay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-08-25')->create(['tenant_id' => $this->kim->id]);
    $this->benStay = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-09-25')->create(['tenant_id' => $this->ben->id]);
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create(['name' => 'Carla']);
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->list = "/api/v1/properties/{$this->property->id}/collections";
});

afterEach(fn () => Date::setTestNow());

function owed(Tenancy $tenancy, string $due, int $amount = 180000): Bill
{
    $bill = Bill::factory()->forTenancy($tenancy)->create(['kind' => BillKind::Periodic, 'issued_on' => $due, 'due_on' => $due]);
    BillLine::factory()->forBill($bill)->create(['kind' => BillLineKind::Rent, 'amount_centavos' => $amount]);

    return $bill;
}

it('lists who is behind, most days overdue first, with what is owed in all', function () {
    owed($this->kimStay, '2026-09-25');
    owed($this->kimStay, '2026-10-25');
    owed($this->kimStay, '2026-11-25'); // not due yet: owed, not overdue
    owed($this->benStay, '2026-10-28', 90000);
    Date::setTestNow(CarbonImmutable::parse('2026-10-30 10:00', 'Asia/Manila'));

    $this->actingAs($this->collector, 'sanctum')->getJson($this->list)->assertOk()
        ->assertJsonPath('meta.payers', 2)
        ->assertJsonPath('meta.overdue_total_centavos', 450000)
        ->assertJsonPath('data.0.payer.name', 'Kim')
        ->assertJsonPath('data.0.payer.phone', '0917 000 0001')
        ->assertJsonPath('data.0.days_overdue', 35)
        ->assertJsonPath('data.0.oldest_due_on', '2026-09-25')
        ->assertJsonPath('data.0.overdue_bills', 2)
        ->assertJsonPath('data.0.overdue_centavos', 360000)
        ->assertJsonPath('data.0.owed_centavos', 540000)
        ->assertJsonPath('data.1.payer.name', 'Ben')
        ->assertJsonPath('data.1.days_overdue', 2);

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson($this->list)->assertNotFound();
});

it('lets a collector follow up after a visit: remark, promised date, reminder, late mark (SC-32)', function () {
    $bill = owed($this->kimStay, '2026-10-27');
    $this->actingAs($this->collector, 'sanctum');

    $this->postJson("{$this->list}/{$this->kim->id}/notes", ['note' => 'Visited. Salary comes Friday.', 'promised_on' => '2026-11-01'])
        ->assertCreated()->assertJsonPath('data.by', 'Carla');
    $this->postJson("{$this->list}/{$this->kim->id}/notes", ['note' => 'X', 'promised_on' => '2026-10-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['promised_on']);

    $this->postJson("{$this->list}/{$this->kim->id}/remind")->assertOk()->assertJsonPath('message', 'Reminder sent to Kim (1 bill).');
    $this->postJson("{$this->list}/{$this->kim->id}/remind")->assertUnprocessable()->assertJsonPath('errors.payer.0', 'Kim was already reminded today.');
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::PaymentOverdue && $n->data['kind'] === 'manual' && $n->data['days_overdue'] === 3);
    expect($bill->reminders()->sole()->kind)->toBe(ReminderKind::Manual)
        ->and($bill->reminders()->sole()->sent_by)->toBe($this->collector->id);

    $this->postJson("/api/v1/bills/{$bill->id}/mark-late")->assertOk()
        ->assertJsonPath('data.is_late', true)
        ->assertJsonPath('data.late_notice_number', 'NTC-2026-000001');
    $this->postJson("/api/v1/bills/{$bill->id}/mark-late")->assertUnprocessable()->assertJsonPath('errors.bill.0', 'This bill is already marked late.');
    expect($bill->fresh()->late_marked_by)->toBe($this->collector->id);

    $this->getJson($this->list)->assertOk()
        ->assertJsonPath('data.0.is_late', true)
        ->assertJsonPath('data.0.last_reminded_on', '2026-10-30')
        ->assertJsonPath('data.0.latest_note.note', 'Visited. Salary comes Friday.')
        ->assertJsonPath('data.0.latest_note.promised_on', '2026-11-01');
    $this->getJson("{$this->list}/{$this->kim->id}/notes")->assertOk()->assertJsonCount(1, 'data');

    // The daily job leaves an already-late bill alone.
    app(ReminderService::class)->run(CarbonImmutable::parse('2026-10-31', 'Asia/Manila'));
    expect($bill->fresh()->late_notice_number)->toBe('NTC-2026-000001');
});

it('only marks an overdue bill late, and only reminds about what is due', function () {
    $notDue = owed($this->kimStay, '2026-11-05');
    $this->actingAs($this->collector, 'sanctum');

    $this->postJson("/api/v1/bills/{$notDue->id}/mark-late")->assertUnprocessable()->assertJsonPath('errors.bill.0', 'Only an overdue bill can be marked late.');
    $this->postJson("{$this->list}/{$this->kim->id}/remind")->assertUnprocessable()->assertJsonPath('errors.payer.0', 'Kim has nothing due to remind them of.');
    $this->postJson("{$this->list}/{$this->ben->id}/notes", ['note' => 'Hi'])->assertNotFound(); // Ben has no bills here
});

it('lets the owner or a Manager put a bill under review, which stops follow-up until it ends', function () {
    $bill = owed($this->kimStay, '2026-10-20');
    $body = ['reason' => 'Kim says she paid by GCash on Oct 20'];

    $this->actingAs($this->collector, 'sanctum')->putJson("/api/v1/bills/{$bill->id}/review", $body)->assertForbidden();
    $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/bills/{$bill->id}/review")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->putJson("/api/v1/bills/{$bill->id}/review", $body)->assertOk()
        ->assertJsonPath('data.under_review.reason', 'Kim says she paid by GCash on Oct 20')
        ->assertJsonPath('data.under_review.by', $this->owner->name);
    $this->putJson("/api/v1/bills/{$bill->id}/review", $body)->assertUnprocessable();

    $this->getJson($this->list)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/bills/{$bill->id}/mark-late")->assertUnprocessable()->assertJsonPath('errors.bill.0', 'This bill is under review. End the review first.');
    $this->postJson("{$this->list}/{$this->kim->id}/remind")->assertUnprocessable();
    app(ReminderService::class)->run(CarbonImmutable::parse('2026-10-30', 'Asia/Manila'));
    expect($bill->fresh()->isLate())->toBeFalse();

    $this->deleteJson("/api/v1/bills/{$bill->id}/review")->assertOk()->assertJsonPath('data.under_review', null);
    $this->deleteJson("/api/v1/bills/{$bill->id}/review")->assertUnprocessable();
    app(ReminderService::class)->run(CarbonImmutable::parse('2026-10-31', 'Asia/Manila'));
    expect($bill->fresh()->isLate())->toBeTrue(); // late marking catches up after the review
});
