<?php

namespace App\Services\Tenancies;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\TenancyStatus;
use App\Enums\UnitStatus;
use App\Models\RentableUnit;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Billing\BillingService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Properties\UnitStatusService;
use App\Support\ManilaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move-out and overstay (C1, SC-11, SC-29).
 *
 * - The owner or a caretaker records the day the boarder actually left
 *   (today or up to 30 days back). Without an end date yet (they left
 *   without notice), a reason is needed and the end date becomes that day.
 * - The tenancy is Ended and the unit Available. In a whole room every
 *   occupant leaves that day; a room leader stops being leader.
 * - The last period's fixed utilities go on a final bill (full period).
 * - The day after the end date, if nobody recorded the move-out, the unit
 *   shows Overstaying (a flag only, no charge) and staff are told once.
 */
class MoveOutService
{
    public function __construct(
        private readonly NoticeService $notices,
        private readonly UnitStatusService $units,
        private readonly BillingService $billing,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function recordMoveOut(Tenancy $tenancy, string $movedOutOn, ?string $reason, User $by): Tenancy
    {
        $today = ManilaDate::today();
        $day = ManilaDate::parse($movedOutOn);
        if ($day->gt($today) || $day->lt($today->subDays(NoticeService::MAX_DAYS_BACK)) || $day->lte($tenancy->moved_in_on)) {
            throw ValidationException::withMessages(['moved_out_on' => 'The move-out day must be after the move-in day, today or up to '.NoticeService::MAX_DAYS_BACK.' days back.']);
        }
        if (! $tenancy->status->isCurrent()) {
            throw ValidationException::withMessages(['moved_out_on' => 'This tenancy has already ended.']);
        }
        if ($tenancy->status === TenancyStatus::Active) {
            if (blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'There is no end date yet. Say why (e.g. left without notice).']);
            }
            $tenancy = $this->notices->setEndDate($tenancy, $day->toDateString(), $reason, $by);
        }

        $tenancy = DB::transaction(function () use ($tenancy, $day, $by) {
            $tenancy = Tenancy::whereKey($tenancy->id)->lockForUpdate()->firstOrFail();
            if ($tenancy->status !== TenancyStatus::NoticeGiven) {
                throw ValidationException::withMessages(['moved_out_on' => 'This tenancy has already ended.']);
            }
            $unit = RentableUnit::whereKey($tenancy->unit_id)->firstOrFail();
            if (in_array($unit->status, [UnitStatus::Leaving, UnitStatus::Overstaying], true)) {
                $this->units->move($unit, UnitStatus::Available);
            }
            $tenancy->update(['status' => TenancyStatus::Ended, 'moved_out_on' => $day->toDateString(), 'moved_out_by' => $by->id]);

            // Everyone listed in a whole room leaves with the leader; a leader of this room stops being one.
            $tenancy->occupants()->whereNull('left_on')->update(['left_on' => $day->toDateString(), 'updated_at' => now()]);
            RoomLeader::current()->where('room_id', $tenancy->room_id)->where('user_id', $tenancy->tenant_id)
                ->update(['ended_on' => $day->toDateString(), 'ended_reason' => 'Moved out', 'updated_at' => now()]);

            return $tenancy;
        });

        $tenancy->load('property.owner', 'tenant', 'room', 'unit');
        $final = $this->billing->issueFinalUtilities($tenancy, $today, $by);
        $overstayed = $tenancy->end_on && $day->gt($tenancy->end_on) ? (int) $tenancy->end_on->diffInDays($day) : 0;

        $this->audit->record(AuditEvent::TenancyEnded, $tenancy, owner: $tenancy->property->owner,
            note: "{$tenancy->tenant->name} · room {$tenancy->room->label()} · moved out {$day->format('M j, Y')}"
                .($overstayed ? " · {$overstayed} day(s) after the end date" : '')
                .($final ? " · final bill {$final->number} ".Money::format($final->totalCentavos()) : ''));
        $this->notifications->send($tenancy->tenant, NotificationEvent::MovedOut, [
            'property_name' => $tenancy->property->name,
            'moved_out_on' => $day->format('M j, Y'),
            'final_bill' => $final ? Money::format($final->totalCentavos()) : null,
        ]);

        return $tenancy;
    }

    /**
     * Daily: tenancies past their end date with no move-out recorded show
     * Overstaying (Leaving → Overstaying). Staff hear about each once.
     *
     * @return int how many were flagged today
     */
    public function flagOverstays(CarbonImmutable $day): int
    {
        $flagged = 0;
        Tenancy::where('status', TenancyStatus::NoticeGiven->value)
            ->whereDate('end_on', '<', $day->toDateString())
            ->whereHas('unit', fn ($q) => $q->where('status', UnitStatus::Leaving->value))
            ->with(['unit', 'property.owner', 'tenant', 'room'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $tenancies) use (&$flagged) {
                foreach ($tenancies as $tenancy) {
                    try {
                        $this->units->move($tenancy->unit, UnitStatus::Overstaying, UnitStatus::Leaving);
                    } catch (\LogicException) {
                        continue; // changed meanwhile (moved out, extended)
                    }
                    $flagged++;
                    $this->notifications->send($tenancy->property->paymentConfirmers(), NotificationEvent::TenancyOverstaying, [
                        'property_id' => $tenancy->property_id,
                        'property_name' => $tenancy->property->name,
                        'tenant_name' => $tenancy->tenant->name,
                        'room_label' => $tenancy->room->label(),
                        'end_on' => $tenancy->end_on->format('M j, Y'),
                    ]);
                }
            });

        return $flagged;
    }
}
