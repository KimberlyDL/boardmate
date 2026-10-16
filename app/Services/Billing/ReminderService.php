<?php

namespace App\Services\Billing;

use App\Enums\AuditEvent;
use App\Enums\DocumentType;
use App\Enums\NotificationEvent;
use App\Enums\ReminderKind;
use App\Models\Bill;
use App\Models\BillReminder;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Numbering\Contracts\NumberingService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payment reminders and late marking (C7, levels 1–3), run daily at 09:00.
 * Days count from each bill's due date; every level follows the property's
 * settings (Payments and reminders) and can be turned off there.
 *
 * - Level 1: due soon (default 3 days before) and due today. No "due today"
 *   for a bill issued today: its "New bill" message already says so.
 * - Level 2: overdue on the chosen days (default days 1 and 3), no penalty.
 * - Level 3: marked late the day after the grace days (default 3, so day 4) with a numbered
 *   first notice (NTC-…).
 *
 * Only bills with something still owed, and not under review, are followed
 * up. A reminder is sent only on its own day: a day the job did not run sends
 * nothing afterwards (it would be out of date). Late marking catches up.
 * The payer gets one message per kind per place per day, listing the bills.
 */
class ReminderService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    /** @return array{reminded: int, marked_late: int} payers reminded, bills marked late */
    public function run(CarbonImmutable $day): array
    {
        $reminded = 0;
        $markedLate = 0;

        Bill::query()
            ->withBalance()->unpaid()
            ->whereNull('under_review_at')
            ->whereDate('due_on', '<=', $day->addDays(31)->toDateString())
            ->with(['property.settings', 'property.owner', 'payer'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $bills) use ($day, &$reminded, &$markedLate) {
                $due = [];
                foreach ($bills as $bill) {
                    $kind = $this->kindOn($bill, $day);
                    if ($kind === ReminderKind::MarkedLate) {
                        $markedLate += $this->markLate($bill, $day, null) ? 1 : 0;
                    } elseif ($kind) {
                        $due["{$bill->payer_id}:{$bill->property_id}:{$kind->value}"][] = [$bill, $kind];
                    }
                }
                foreach ($due as $group) {
                    $reminded += $this->remind(collect($group)->pluck(0), $group[0][1], $day, null) ? 1 : 0;
                }
            }, 'bills.id', 'id');

        return ['reminded' => $reminded, 'marked_late' => $markedLate];
    }

    /** Which follow-up, if any, this bill gets on $day under its property's settings. */
    public function kindOn(Bill $bill, CarbonImmutable $day): ?ReminderKind
    {
        $s = $bill->property->settings ?? new PropertySettings;
        $offset = (int) $bill->due_on->diffInDays($day, false);

        // Late the day after the grace days (owner decision 2026-10-16): 3 grace days → day 4.
        if ($s->mark_late_enabled && $offset > $s->grace_days) {
            return $bill->isLate() ? null : ReminderKind::MarkedLate;
        }
        if ($s->remind_before_due && $offset === -$s->remind_before_days) {
            return ReminderKind::DueSoon;
        }
        if ($s->remind_on_due && $offset === 0 && ! $bill->issued_on->isSameDay($day)) {
            return ReminderKind::DueToday;
        }
        if ($s->remind_overdue && $offset > 0 && in_array($offset, array_map('intval', $s->overdue_reminder_days ?? []), true)) {
            return ReminderKind::Overdue;
        }

        return null;
    }

    /**
     * Log and send one reminder for these bills of one payer at one place.
     * Bills already reminded of this kind today are left out; returns whether
     * anything was sent.
     *
     * @param  Collection<int, Bill>  $bills  loaded with balance, property and payer
     */
    public function remind(Collection $bills, ReminderKind $kind, CarbonImmutable $day, ?int $sentBy): bool
    {
        // ON CONFLICT DO NOTHING: a second run (or a reminder already sent today)
        // inserts nothing, without aborting a surrounding Postgres transaction.
        $sent = $bills->filter(fn (Bill $bill) => $this->log($bill, $kind, $day, $sentBy))->values();

        if ($sent->isEmpty()) {
            return false;
        }

        $first = $sent->first();
        $event = match ($kind) {
            ReminderKind::DueSoon, ReminderKind::DueToday => NotificationEvent::PaymentDue,
            default => NotificationEvent::PaymentOverdue,
        };
        $this->notifications->send($first->payer, $event, [
            'property_name' => $first->property->name,
            'kind' => $kind->value,
            'total' => Money::format($sent->sum(fn (Bill $b) => $b->balanceCentavos())),
            'bills' => $sent->map(fn (Bill $b) => [
                'number' => $b->number,
                'due_on' => $b->due_on->format('M j, Y'),
                'balance' => Money::format($b->balanceCentavos()),
            ])->all(),
            'due_on' => $sent->min('due_on')->format('M j, Y'),
            'days_overdue' => max(0, (int) $sent->min('due_on')->diffInDays($day, false)),
        ]);

        return true;
    }

    /**
     * Level 3: mark an unpaid bill late with a numbered first notice, and tell
     * the payer. By the daily job ($by null) or by staff from the overdue
     * list. Returns false if it was already late, paid, or under review.
     */
    public function markLate(Bill $bill, CarbonImmutable $day, ?int $by): bool
    {
        $marked = DB::transaction(function () use ($bill, $by) {
            $locked = Bill::whereKey($bill->id)->withBalance()->lockForUpdate()->firstOrFail();
            if ($locked->isLate() || $locked->isUnderReview() || $locked->balanceCentavos() <= 0) {
                return null;
            }
            $bill->loadMissing('property.owner');
            $locked->update([
                'late_at' => now(),
                'late_notice_number' => $this->numbering->next($bill->property->owner, DocumentType::Notice),
                'late_marked_by' => $by,
            ]);

            return $locked;
        });

        if (! $marked) {
            return false;
        }

        $bill->loadMissing('payer', 'property.owner');
        $days = (int) $bill->due_on->diffInDays($day, false);
        $this->log($marked, ReminderKind::MarkedLate, $day, $by);
        $this->audit->record(AuditEvent::BillMarkedLate, $marked, owner: $bill->property->owner,
            note: "{$bill->number} · {$bill->payer->name} · ".Money::format($marked->balanceCentavos())." · {$days} day(s) overdue · {$marked->late_notice_number}");
        $this->notifications->send($bill->payer, NotificationEvent::BillMarkedLate, [
            'property_name' => $bill->property->name,
            'bill_number' => $bill->number,
            'notice_number' => $marked->late_notice_number,
            'balance' => Money::format($marked->balanceCentavos()),
            'due_on' => $bill->due_on->format('M j, Y'),
            'days_overdue' => $days,
        ]);

        return true;
    }

    /** Record a reminder; false when one of this kind was already sent for the bill that day. */
    private function log(Bill $bill, ReminderKind $kind, CarbonImmutable $day, ?int $sentBy): bool
    {
        return BillReminder::insertOrIgnore([
            'bill_id' => $bill->id,
            'payer_id' => $bill->payer_id,
            'kind' => $kind->value,
            'sent_on' => $day->toDateString(),
            'balance_centavos' => $bill->balanceCentavos(),
            'sent_by' => $sentBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
