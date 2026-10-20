<?php

namespace App\Services\HouseOperations;

use App\Enums\LateEntryStatus;
use App\Models\LateEntryRequest;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Late-entry requests (D2), kept plain: a tenant asks ahead, staff approve or
 * decline. Only where the property has a curfew.
 */
class LateEntryService
{
    /** @param  array{on_date: string, expected_arrival: string, reason: string}  $data */
    public function request(Tenancy $tenancy, User $by, array $data): LateEntryRequest
    {
        $tenancy->loadMissing('property.settings');
        if (! $tenancy->status->isCurrent()) {
            throw ValidationException::withMessages(['tenancy' => 'This stay has ended.']);
        }
        if (! $tenancy->property->settings?->curfew_time) {
            throw ValidationException::withMessages(['on_date' => 'This place has no curfew, so there is nothing to ask.']);
        }

        $today = ManilaDate::today();
        $on = CarbonImmutable::parse($data['on_date'], 'Asia/Manila')->startOfDay();
        if ($on->lt($today) || $on->gt($today->addDays(LateEntryRequest::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['on_date' => 'Choose today or a day within the next '.LateEntryRequest::MAX_DAYS_AHEAD.' days.']);
        }
        if (LateEntryRequest::where('tenancy_id', $tenancy->id)->whereDate('on_date', $on->toDateString())->whereIn('status', LateEntryStatus::openValues())->exists()) {
            throw ValidationException::withMessages(['on_date' => 'You already asked for that day. Cancel it first to ask again.']);
        }

        return LateEntryRequest::create([
            'property_id' => $tenancy->property_id,
            'tenancy_id' => $tenancy->id,
            'requested_by' => $by->id,
            'on_date' => $on->toDateString(),
            'expected_arrival' => $data['expected_arrival'],
            'reason' => trim($data['reason']),
            'status' => LateEntryStatus::Pending,
        ]);
    }

    public function approve(LateEntryRequest $request, User $by): LateEntryRequest
    {
        $this->expectPending($request);
        $request->update(['status' => LateEntryStatus::Approved, 'decided_by' => $by->id, 'decided_at' => now()]);

        return $request;
    }

    public function decline(LateEntryRequest $request, User $by, string $reason): LateEntryRequest
    {
        $this->expectPending($request);
        $request->update(['status' => LateEntryStatus::Declined, 'decided_by' => $by->id, 'decided_at' => now(), 'decline_reason' => $reason]);

        return $request;
    }

    /** The tenant takes it back, while it is waiting or approved and the day has not passed. */
    public function cancel(LateEntryRequest $request): LateEntryRequest
    {
        if (! $request->status->isOpen() || $request->on_date->lt(ManilaDate::today())) {
            throw ValidationException::withMessages(['status' => 'This request can no longer be cancelled.']);
        }
        $request->update(['status' => LateEntryStatus::Cancelled]);

        return $request;
    }

    /** Was coming back late on $day approved for this stay? (A violation then is not logged.) */
    public function isApproved(Tenancy $tenancy, CarbonImmutable $day): bool
    {
        return LateEntryRequest::where('tenancy_id', $tenancy->id)->whereDate('on_date', $day->toDateString())
            ->where('status', LateEntryStatus::Approved)->exists();
    }

    private function expectPending(LateEntryRequest $request): void
    {
        if ($request->status !== LateEntryStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }
    }
}
