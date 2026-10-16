<?php

namespace App\Services\Billing;

use App\Enums\AuditEvent;
use App\Enums\ReminderKind;
use App\Models\Bill;
use App\Models\BillReminder;
use App\Models\CollectionNote;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Support\ManilaDate;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The overdue list and what staff do from it (C7, SC-32): who is behind and
 * by how much, remarks with a promised date, a reminder now, marking a bill
 * late, and putting a bill under review.
 *
 * In a room rented whole, and for a room's shared utility bill, the payer is
 * the room leader, so the leader is the one listed as overdue.
 */
class CollectionService
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly AuditService $audit,
    ) {}

    /**
     * Everyone with an overdue bill at the property, most days overdue first.
     * Bills under review are counted apart and not in `overdue_centavos`.
     *
     * @return list<array<string, mixed>>
     */
    public function overdue(Property $property): array
    {
        $today = ManilaDate::today();
        $unpaid = Bill::where('property_id', $property->id)
            ->withBalance()->unpaid()
            ->with(['payer:id,name,phone', 'room'])
            ->orderBy('due_on')
            ->get();

        $rows = $unpaid->groupBy('payer_id')
            ->map(function (Collection $bills) use ($today) {
                $overdue = $bills->filter(fn (Bill $b) => $b->due_on->lt($today) && ! $b->isUnderReview());
                if ($overdue->isEmpty()) {
                    return null;
                }
                $oldest = $overdue->first();

                return [
                    'payer' => ['id' => $oldest->payer_id, 'name' => $oldest->payer?->name, 'phone' => $oldest->payer?->phone],
                    'room_label' => $oldest->room?->label(),
                    'overdue_centavos' => $overdue->sum(fn (Bill $b) => $b->balanceCentavos()),
                    'owed_centavos' => $bills->sum(fn (Bill $b) => $b->balanceCentavos()),
                    'overdue_bills' => $overdue->count(),
                    'oldest_due_on' => $oldest->due_on->toDateString(),
                    'days_overdue' => (int) $oldest->due_on->diffInDays($today),
                    'is_late' => $overdue->contains(fn (Bill $b) => $b->isLate()),
                    'under_review_bills' => $bills->filter(fn (Bill $b) => $b->isUnderReview())->count(),
                ];
            })
            ->filter()
            ->sortByDesc('days_overdue')
            ->values();

        $payerIds = $rows->pluck('payer.id')->all();
        $lastReminded = BillReminder::whereIn('payer_id', $payerIds)
            ->whereHas('bill', fn ($q) => $q->where('property_id', $property->id))
            ->selectRaw('payer_id, max(sent_on) as last_on')->groupBy('payer_id')->pluck('last_on', 'payer_id');
        $notes = CollectionNote::where('property_id', $property->id)->whereIn('payer_id', $payerIds)
            ->with('author:id,name')->latest('id')->get()->unique('payer_id')->keyBy('payer_id');

        return $rows->map(fn (array $row) => $row + [
            'last_reminded_on' => isset($lastReminded[$row['payer']['id']]) ? substr((string) $lastReminded[$row['payer']['id']], 0, 10) : null,
            'latest_note' => ($n = $notes->get($row['payer']['id'])) ? $this->presentNote($n) : null,
        ])->all();
    }

    public function addNote(Property $property, User $payer, string $note, ?string $promisedOn, User $by): CollectionNote
    {
        return CollectionNote::create([
            'property_id' => $property->id,
            'payer_id' => $payer->id,
            'note' => $note,
            'promised_on' => $promisedOn,
            'created_by' => $by->id,
        ])->load('author:id,name');
    }

    /** @return array{id: int, note: string, promised_on: ?string, by: ?string, at: ?string} */
    public function presentNote(CollectionNote $note): array
    {
        return [
            'id' => $note->id,
            'note' => $note->note,
            'promised_on' => $note->promised_on?->toDateString(),
            'by' => $note->author?->name,
            'at' => $note->created_at?->toIso8601String(),
        ];
    }

    /**
     * A reminder from staff for the payer's bills due today or earlier (not
     * under review), at most once a day. Returns how many bills it covered.
     */
    public function remindNow(Property $property, User $payer, User $by): int
    {
        $today = ManilaDate::today();
        $bills = Bill::where('property_id', $property->id)->where('payer_id', $payer->id)
            ->withBalance()->unpaid()
            ->whereNull('under_review_at')
            ->whereDate('due_on', '<=', $today->toDateString())
            ->with(['property', 'payer'])
            ->orderBy('due_on')
            ->get();

        if ($bills->isEmpty()) {
            throw ValidationException::withMessages(['payer' => "{$payer->name} has nothing due to remind them of."]);
        }

        $already = BillReminder::whereIn('bill_id', $bills->modelKeys())->where('kind', ReminderKind::Manual->value)
            ->whereDate('sent_on', $today->toDateString())->pluck('bill_id');
        $bills = $bills->reject(fn (Bill $b) => $already->contains($b->id))->values();
        if ($bills->isEmpty() || ! $this->reminders->remind($bills, ReminderKind::Manual, $today, $by->id)) {
            throw ValidationException::withMessages(['payer' => "{$payer->name} was already reminded today."]);
        }

        return $bills->count();
    }

    /** Staff mark an overdue bill late now (before the automatic day), with its notice. */
    public function markLate(Bill $bill, User $by): void
    {
        $bill->loadMissing('property', 'payer');
        $today = ManilaDate::today();
        $reason = match (true) {
            $bill->isLate() => 'This bill is already marked late.',
            $bill->isUnderReview() => 'This bill is under review. End the review first.',
            ! $bill->due_on->lt($today) => 'Only an overdue bill can be marked late.',
            $bill->balanceCentavos() <= 0 => 'This bill is paid.',
            default => null,
        };
        if ($reason || ! $this->reminders->markLate($bill, $today, $by->id)) {
            throw ValidationException::withMessages(['bill' => $reason ?? 'This bill cannot be marked late now.']);
        }
    }

    /** Put a bill under review (the payer disputes it or says they paid): no reminders, no late marking. */
    public function startReview(Bill $bill, string $reason, User $by): Bill
    {
        if ($bill->isUnderReview()) {
            throw ValidationException::withMessages(['bill' => 'This bill is already under review.']);
        }
        $bill->update(['under_review_at' => now(), 'under_review_by' => $by->id, 'under_review_reason' => $reason]);
        $bill->loadMissing('property.owner', 'payer');
        $this->audit->record(AuditEvent::BillReviewStarted, $bill, owner: $bill->property->owner, reason: $reason,
            note: "{$bill->number} · {$bill->payer->name}");

        return $bill;
    }

    /** End the review: reminders and late marking resume from the next daily run. */
    public function endReview(Bill $bill): Bill
    {
        if (! $bill->isUnderReview()) {
            throw ValidationException::withMessages(['bill' => 'This bill is not under review.']);
        }
        $was = $bill->under_review_reason;
        $bill->update(['under_review_at' => null, 'under_review_by' => null, 'under_review_reason' => null]);
        $bill->loadMissing('property.owner', 'payer');
        $this->audit->record(AuditEvent::BillReviewEnded, $bill, owner: $bill->property->owner,
            note: "{$bill->number} · {$bill->payer->name} · was: {$was} · ".Money::format($bill->balanceCentavos()).' owed');

        return $bill;
    }
}
