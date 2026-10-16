<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\NotificationEvent;
use App\Enums\ReminderKind;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillReminder;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Billing\ReminderService;
use App\Services\Notifications\DormLinkNotification;
use App\Services\Scheduler\Jobs\IssueRentBillsJob;
use App\Services\Scheduler\Jobs\SendPaymentRemindersJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 09:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

/** Kim's ₱1,800 bill due Oct 25, issued on $issued. */
function dueBill(Tenancy $tenancy, string $issued = '2026-10-20', string $due = '2026-10-25', int $amount = 180000, BillKind $kind = BillKind::Charge): Bill
{
    $bill = Bill::factory()->forTenancy($tenancy)->create(['kind' => $kind, 'issued_on' => $issued, 'due_on' => $due]);
    BillLine::factory()->forBill($bill)->create(['kind' => BillLineKind::Rent, 'amount_centavos' => $amount]);

    return $bill;
}

/** Run the reminder job as of a Manila day. */
function remindOn(string $day): array
{
    Date::setTestNow(CarbonImmutable::parse("{$day} 09:00", 'Asia/Manila'));

    return app(ReminderService::class)->run(CarbonImmutable::parse($day, 'Asia/Manila'));
}

/**
 * The reminder messages Kim got, in order. The service delivers in-app and
 * email as two sends of the same message, so each is counted once.
 *
 * @return list<DormLinkNotification>
 */
function kimReminders(User $kim): array
{
    return Notification::sent($kim, DormLinkNotification::class)
        ->filter(fn ($n) => in_array($n->event, [NotificationEvent::PaymentDue, NotificationEvent::PaymentOverdue, NotificationEvent::BillMarkedLate], true))
        ->unique(fn ($n) => spl_object_id($n))
        ->values()
        ->all();
}

/** @return list<string> */
function kimEvents(User $kim): array
{
    return array_map(fn ($n) => $n->event === NotificationEvent::PaymentDue ? $n->data['kind'] : $n->event->value, kimReminders($kim));
}

it('follows the default schedule: 3 days before, due date, days 1 and 3, then late on day 4 with a notice (SC-16)', function () {
    $bill = dueBill($this->tenancy);

    foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'] as $day) {
        remindOn($day);
    }

    expect(kimEvents($this->kim))->toBe(['due_soon', 'due_today', 'payment_overdue', 'payment_overdue', 'bill_marked_late'])
        ->and($bill->reminders()->get()->map(fn ($r) => [$r->kind, $r->sent_on->toDateString()])->all())->toBe([
            [ReminderKind::DueSoon, '2026-10-22'],
            [ReminderKind::DueToday, '2026-10-25'],
            [ReminderKind::Overdue, '2026-10-26'],
            [ReminderKind::Overdue, '2026-10-28'],
            [ReminderKind::MarkedLate, '2026-10-29'],
        ]);

    $bill->refresh();
    expect($bill->isLate())->toBeTrue()
        ->and($bill->late_notice_number)->toBe('NTC-2026-000001')
        ->and($bill->late_marked_by)->toBeNull();

    [, , , $day3, $late] = kimReminders($this->kim);
    expect($day3->data['days_overdue'])->toBe(3)
        ->and($day3->data['total'])->toBe('₱1,800.00')
        ->and($late->data['notice_number'])->toBe('NTC-2026-000001')
        ->and($late->data['days_overdue'])->toBe(4);
});

it('skips "due today" for a bill issued that day, and never reminds twice in a day', function () {
    dueBill($this->tenancy, issued: '2026-10-25', kind: BillKind::Periodic);

    remindOn('2026-10-25');
    remindOn('2026-10-25');
    remindOn('2026-10-26');
    remindOn('2026-10-26');

    expect(kimEvents($this->kim))->toBe(['payment_overdue'])
        ->and(BillReminder::count())->toBe(1);
});

it('reminds only for what is still owed: nothing when credit covers it (SC-34), the balance when partly paid', function () {
    $covered = dueBill($this->tenancy);
    Payment::factory()->forPayer($this->property, $this->kim)->confirmed()->create(['amount_centavos' => 180000, 'paid_on' => '2026-10-20']);
    app(PaymentLedger::class)->settle($this->property->id, $this->kim->id);

    remindOn('2026-10-22');
    expect(kimEvents($this->kim))->toBe([]);

    $partly = dueBill($this->tenancy, due: '2026-10-29');
    Payment::factory()->forPayer($this->property, $this->kim)->confirmed()->create(['amount_centavos' => 50000, 'paid_on' => '2026-10-23']);
    app(PaymentLedger::class)->settle($this->property->id, $this->kim->id);

    remindOn('2026-10-26');
    [$due] = kimReminders($this->kim);
    expect($due->data['kind'])->toBe('due_soon')
        ->and($due->data['total'])->toBe('₱1,300.00')
        ->and($due->data['bills'][0]['number'])->toBe($partly->number)
        ->and($covered->reminders()->count())->toBe(0);
});

it('puts several bills due the same day in one message', function () {
    dueBill($this->tenancy);
    dueBill($this->tenancy, amount: 30000);

    remindOn('2026-10-22');

    $sent = kimReminders($this->kim);
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->data['total'])->toBe('₱2,100.00')
        ->and($sent[0]->data['bills'])->toHaveCount(2)
        ->and(BillReminder::count())->toBe(2);
});

it('leaves bills under review alone, and follows the settings when a level is off or retimed', function () {
    $review = dueBill($this->tenancy);
    $review->update(['under_review_at' => now(), 'under_review_by' => $this->owner->id, 'under_review_reason' => 'Kim says she paid']);
    $this->property->settings->update(['remind_before_due' => false, 'remind_overdue' => false, 'mark_late_enabled' => false]);
    $plain = dueBill($this->tenancy, due: '2026-10-26');

    foreach (['2026-10-22', '2026-10-23', '2026-10-26', '2026-10-27', '2026-10-30', '2026-11-05'] as $day) {
        remindOn($day);
    }

    expect(kimEvents($this->kim))->toBe(['due_today'])
        ->and($review->reminders()->count())->toBe(0)
        ->and($plain->fresh()->isLate())->toBeFalse();

    // Retimed: remind 5 days before, overdue on day 2, 6 grace days so late on day 7.
    $this->property->settings->update(['remind_before_due' => true, 'remind_before_days' => 5, 'remind_overdue' => true, 'overdue_reminder_days' => [2], 'mark_late_enabled' => true, 'grace_days' => 6]);
    $retimed = dueBill($this->tenancy, issued: '2026-11-05', due: '2026-11-15');
    foreach (['2026-11-10', '2026-11-17', '2026-11-21', '2026-11-22'] as $day) {
        remindOn($day);
    }
    expect($retimed->reminders()->pluck('kind')->all())->toBe([ReminderKind::DueSoon, ReminderKind::Overdue, ReminderKind::MarkedLate])
        ->and($retimed->reminders()->get()->last()->sent_on->toDateString())->toBe('2026-11-22');
});

it('does not send old reminders for missed days, but still marks late', function () {
    $bill = dueBill($this->tenancy);

    remindOn('2026-11-02'); // the job did not run Oct 21 – Nov 1

    $sent = kimReminders($this->kim);
    expect(kimEvents($this->kim))->toBe(['bill_marked_late'])
        ->and($sent[0]->data['days_overdue'])->toBe(8)
        ->and($bill->fresh()->isLate())->toBeTrue();
});

it('runs as a daily job right after the day\'s bills are issued', function () {
    $jobs = config('dormlink.daily_jobs');

    expect(array_search(SendPaymentRemindersJob::class, $jobs, true))->toBe(array_search(IssueRentBillsJob::class, $jobs, true) + 1);

    dueBill($this->tenancy);
    $summary = app(SendPaymentRemindersJob::class)->run(CarbonImmutable::parse('2026-10-22', 'Asia/Manila'));
    expect($summary)->toBe('Reminded 1 payer(s); marked 0 bill(s) late.');
});

it('runs end to end through the daily command: bill on Oct 25, reminders, late on day 4, then paid (worked example)', function () {
    $days = ['2026-10-22', '2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'];
    foreach ($days as $day) {
        Date::setTestNow(CarbonImmutable::parse("{$day} 09:00", 'Asia/Manila'));
        $this->artisan('dormlink:daily')->assertSuccessful();
        $this->artisan('dormlink:daily')->assertSuccessful(); // a second run that day changes nothing
    }

    // Bill issued on its due date with the "New bill" message, so no "due today"; then days 1 and 3, then late.
    $bill = Bill::sole();
    expect($bill->issued_on->toDateString())->toBe('2026-10-25')
        ->and(kimEvents($this->kim))->toBe(['payment_overdue', 'payment_overdue', 'bill_marked_late'])
        ->and($bill->fresh()->late_notice_number)->toBe('NTC-2026-000001');

    // Kim pays in full: nothing more is sent, and the bill stays on record as late.
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/payments", [
        'payer_id' => $this->kim->id, 'amount_centavos' => $bill->totalCentavos(), 'method' => 'cash', 'paid_on' => '2026-10-30',
    ])->assertCreated();
    Date::setTestNow(CarbonImmutable::parse('2026-10-31 09:00', 'Asia/Manila'));
    $this->artisan('dormlink:daily')->assertSuccessful();

    expect(kimEvents($this->kim))->toHaveCount(3)
        ->and($bill->fresh()->balanceCentavos())->toBe(0)
        ->and($bill->fresh()->isLate())->toBeTrue();
    $this->getJson("/api/v1/properties/{$this->property->id}/collections")->assertOk()->assertJsonCount(0, 'data');
});
