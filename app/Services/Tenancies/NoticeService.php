<?php

namespace App\Services\Tenancies;

use App\Enums\AuditEvent;
use App\Enums\NoticeStatus;
use App\Enums\NotificationEvent;
use App\Enums\TenancyStatus;
use App\Enums\UnitStatus;
use App\Models\PropertySettings;
use App\Models\RentableUnit;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Properties\UnitStatusService;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Notice and end dates (C1, SC-10, SC-11, SC-29). The end date is the last
 * day of stay; the unit is free the day after.
 *
 * - The boarder (in a whole room, the leader) files an end date from today
 *   on. It waits for the owner or a caretaker, who approves or declines it.
 * - Staff can set an end date themselves (the boarder left without notice,
 *   or an agreed date), change it (extend, bring forward) or cancel it,
 *   always with a reason. It may be up to 30 days back, for someone who
 *   already left.
 * - Approving or setting an end date makes the tenancy "Leaving" and the
 *   unit Leaving; cancelling makes both current again.
 * - Short notice (fewer days than the property's minimum) is only flagged.
 */
class NoticeService
{
    public const MAX_DAYS_BACK = 30;

    public function __construct(
        private readonly UnitStatusService $units,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function file(Tenancy $tenancy, string $endOn, User $by): TenancyNotice
    {
        $notice = DB::transaction(function () use ($tenancy, $endOn, $by) {
            $tenancy = $this->lock($tenancy);
            if ($tenancy->status !== TenancyStatus::Active) {
                throw ValidationException::withMessages(['end_on' => 'You already have an end date. Ask the owner to change it.']);
            }
            if ($tenancy->pendingNotice()->exists()) {
                throw ValidationException::withMessages(['end_on' => 'Your notice is already waiting for approval. Withdraw it first to change the date.']);
            }
            $end = $this->checkDate($tenancy, $endOn, ManilaDate::today());

            return $tenancy->notices()->create([
                'end_on' => $end->toDateString(),
                'status' => NoticeStatus::Pending,
                'is_short_notice' => $this->isShort($tenancy, $end),
                'filed_by' => $by->id,
            ]);
        });

        $tenancy->load('property.owner', 'tenant', 'room');
        $this->audit->record(AuditEvent::NoticeFiled, $notice, owner: $tenancy->property->owner,
            note: $this->describe($tenancy, $notice));
        $this->notifications->send($tenancy->property->paymentConfirmers(), NotificationEvent::NoticeFiled, [
            'property_id' => $tenancy->property_id,
            'property_name' => $tenancy->property->name,
            'tenant_name' => $tenancy->tenant->name,
            'room_label' => $tenancy->room->label(),
            'end_on' => $notice->end_on->format('M j, Y'),
            'is_short_notice' => $notice->is_short_notice,
        ]);

        return $notice;
    }

    /** The boarder takes back a notice still waiting for a decision. */
    public function withdraw(Tenancy $tenancy, User $by): void
    {
        $notice = DB::transaction(function () use ($tenancy, $by) {
            $notice = $this->lock($tenancy)->pendingNotice()->first();
            if (! $notice) {
                throw ValidationException::withMessages(['notice' => 'There is no notice waiting to withdraw.']);
            }
            $notice->update(['status' => NoticeStatus::Withdrawn, 'decided_by' => $by->id, 'decided_at' => now()]);

            return $notice;
        });

        $tenancy->load('property.owner', 'tenant', 'room');
        $this->audit->record(AuditEvent::NoticeWithdrawn, $notice, owner: $tenancy->property->owner, note: $this->describe($tenancy, $notice));
    }

    public function approve(Tenancy $tenancy, User $by): Tenancy
    {
        $notice = DB::transaction(function () use ($tenancy, $by) {
            $tenancy = $this->lock($tenancy);
            $notice = $tenancy->pendingNotice()->first();
            if (! $notice) {
                throw ValidationException::withMessages(['notice' => 'There is no notice waiting for approval.']);
            }
            $notice->update(['status' => NoticeStatus::Approved, 'decided_by' => $by->id, 'decided_at' => now()]);
            $this->applyEnd($tenancy, $notice->end_on);

            return $notice;
        });

        return $this->announce($tenancy, $notice, AuditEvent::NoticeApproved, 'approved', null);
    }

    public function decline(Tenancy $tenancy, string $reason, User $by): void
    {
        $notice = DB::transaction(function () use ($tenancy, $reason, $by) {
            $notice = $this->lock($tenancy)->pendingNotice()->first();
            if (! $notice) {
                throw ValidationException::withMessages(['notice' => 'There is no notice waiting for approval.']);
            }
            $notice->update(['status' => NoticeStatus::Declined, 'decided_by' => $by->id, 'decided_at' => now(), 'decline_reason' => $reason]);

            return $notice;
        });

        $this->announce($tenancy, $notice, AuditEvent::NoticeDeclined, 'declined', $reason);
    }

    /**
     * Staff set or change the end date (no notice given, an agreed date, an
     * extension). A boarder's notice still waiting is replaced by it.
     */
    public function setEndDate(Tenancy $tenancy, string $endOn, string $reason, User $by): Tenancy
    {
        $notice = DB::transaction(function () use ($tenancy, $endOn, $reason, $by) {
            $tenancy = $this->lock($tenancy);
            if (! $tenancy->status->isCurrent()) {
                throw ValidationException::withMessages(['end_on' => 'This tenancy has already ended.']);
            }
            $today = ManilaDate::today();
            $end = $this->checkDate($tenancy, $endOn, $today->subDays(self::MAX_DAYS_BACK));
            if ($tenancy->end_on?->equalTo($end)) {
                throw ValidationException::withMessages(['end_on' => 'That is already the end date.']);
            }

            $tenancy->notices()->whereIn('status', [NoticeStatus::Pending->value, NoticeStatus::Approved->value])
                ->update(['status' => NoticeStatus::Replaced->value, 'decided_by' => $by->id, 'decided_at' => now()]);
            $notice = $tenancy->notices()->create([
                'end_on' => $end->toDateString(),
                'status' => NoticeStatus::Approved,
                'is_short_notice' => $this->isShort($tenancy, $end),
                'reason' => $reason,
                'filed_by' => $by->id,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);
            $this->applyEnd($tenancy, $end);

            return $notice;
        });

        return $this->announce($tenancy, $notice, AuditEvent::EndDateSet, 'set', $reason);
    }

    /** Staff cancel the end date: the boarder stays on, month to month. */
    public function cancelEndDate(Tenancy $tenancy, string $reason, User $by): Tenancy
    {
        $notice = DB::transaction(function () use ($tenancy, $by) {
            $tenancy = $this->lock($tenancy);
            if ($tenancy->status !== TenancyStatus::NoticeGiven) {
                throw ValidationException::withMessages(['end_on' => 'This tenancy has no end date to cancel.']);
            }
            $notice = $tenancy->notices()->where('status', NoticeStatus::Approved->value)->latest('id')->first();
            $notice?->update(['status' => NoticeStatus::Withdrawn, 'decided_by' => $by->id, 'decided_at' => now()]);

            $unit = RentableUnit::whereKey($tenancy->unit_id)->firstOrFail();
            if ($unit->status === UnitStatus::Overstaying) {
                $this->units->move($unit, UnitStatus::Leaving);
            }
            if ($unit->status === UnitStatus::Leaving) {
                $this->units->move($unit, UnitStatus::Occupied);
            }
            $tenancy->update(['status' => TenancyStatus::Active, 'end_on' => null]);

            return $notice;
        });

        $tenancy->refresh()->load('property.owner', 'tenant', 'room');
        $this->audit->record(AuditEvent::EndDateCancelled, $tenancy, owner: $tenancy->property->owner, reason: $reason,
            note: "{$tenancy->tenant->name} · room {$tenancy->room->label()}".($notice ? ' · was '.$notice->end_on->format('M j, Y') : ''));
        $this->notifications->send($tenancy->tenant, NotificationEvent::EndDateChanged, [
            'property_name' => $tenancy->property->name,
            'change' => 'cancelled',
            'end_on' => null,
            'reason' => $reason,
        ]);

        return $tenancy;
    }

    /** Tenancy Leaving with this end date; the unit Leaving (or Overstaying → Leaving when extended). */
    private function applyEnd(Tenancy $tenancy, CarbonImmutable $end): void
    {
        $unit = RentableUnit::whereKey($tenancy->unit_id)->firstOrFail();
        if ($unit->status === UnitStatus::Occupied || $unit->status === UnitStatus::Overstaying) {
            $this->units->move($unit, UnitStatus::Leaving);
        }
        $tenancy->update(['status' => TenancyStatus::NoticeGiven, 'end_on' => $end->toDateString()]);
    }

    private function checkDate(Tenancy $tenancy, string $endOn, CarbonImmutable $earliest): CarbonImmutable
    {
        $end = ManilaDate::parse($endOn);
        if ($end->lt($earliest)) {
            throw ValidationException::withMessages(['end_on' => 'The end date can be '.($earliest->isSameDay(ManilaDate::today()) ? 'today or later.' : 'no earlier than '.$earliest->format('M j, Y').'.')]);
        }
        if ($end->lte($tenancy->moved_in_on)) {
            throw ValidationException::withMessages(['end_on' => 'The end date must be after the move-in date ('.$tenancy->moved_in_on->format('M j, Y').').']);
        }

        return $end;
    }

    /** Fewer days' notice than the property's minimum (if it has one). Flag only. */
    private function isShort(Tenancy $tenancy, CarbonImmutable $end): bool
    {
        $minimum = ($tenancy->property->settings ?? new PropertySettings)->minimum_notice_days;

        return $minimum !== null && (int) ManilaDate::today()->diffInDays($end, false) < $minimum;
    }

    private function lock(Tenancy $tenancy): Tenancy
    {
        return Tenancy::whereKey($tenancy->id)->lockForUpdate()->with('property.settings')->firstOrFail();
    }

    private function announce(Tenancy $tenancy, TenancyNotice $notice, AuditEvent $event, string $change, ?string $reason): Tenancy
    {
        $tenancy->refresh()->load('property.owner', 'tenant', 'room');
        $this->audit->record($event, $notice, owner: $tenancy->property->owner, reason: $reason, note: $this->describe($tenancy, $notice));
        $this->notifications->send($tenancy->tenant, NotificationEvent::EndDateChanged, [
            'property_name' => $tenancy->property->name,
            'change' => $change,
            'end_on' => $notice->end_on->format('M j, Y'),
            'reason' => $reason,
        ]);

        return $tenancy;
    }

    private function describe(Tenancy $tenancy, TenancyNotice $notice): string
    {
        return "{$tenancy->tenant->name} · room {$tenancy->room->label()} · last day {$notice->end_on->format('M j, Y')}"
            .($notice->is_short_notice ? ' · short notice' : '');
    }
}
