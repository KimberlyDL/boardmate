<?php

namespace App\Services\HouseOperations;

use App\Enums\VisitorStatus;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Visitors (D4), kept plain: registered ahead by the tenant or logged as a
 * walk-in by staff; overnight stays need approval when the property says
 * so, and nights over the monthly limit are flagged, never blocked or
 * charged by the app.
 */
class VisitorService
{
    /**
     * The tenant registers a visit.
     *
     * @param  array{name: string, contact?: ?string, visit_on: string, expected_in?: ?string, expected_out?: ?string, overnight?: bool, nights?: ?int}  $data
     */
    public function register(Tenancy $tenancy, User $by, array $data): Visitor
    {
        if (! $tenancy->status->isCurrent()) {
            throw ValidationException::withMessages(['tenancy' => 'This stay has ended.']);
        }
        $today = ManilaDate::today();
        $on = CarbonImmutable::parse($data['visit_on'], 'Asia/Manila')->startOfDay();
        if ($on->lt($today) || $on->gt($today->addDays(Visitor::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['visit_on' => 'Choose today or a day within the next '.Visitor::MAX_DAYS_AHEAD.' days.']);
        }

        $overnight = (bool) ($data['overnight'] ?? false);
        $settings = $this->settings($tenancy);
        $needsApproval = $overnight && $settings->overnight_requires_approval;

        $visitor = new Visitor([
            'property_id' => $tenancy->property_id,
            'tenancy_id' => $tenancy->id,
            'registered_by' => $by->id,
            'name' => trim($data['name']),
            'contact' => isset($data['contact']) ? trim($data['contact']) ?: null : null,
            'visit_on' => $on->toDateString(),
            'expected_in' => $data['expected_in'] ?? null,
            'expected_out' => $data['expected_out'] ?? null,
            'overnight' => $overnight,
            'nights' => $overnight ? max(1, (int) ($data['nights'] ?? 1)) : 0,
            'status' => $needsApproval ? VisitorStatus::Pending : VisitorStatus::Registered,
        ]);
        $visitor->over_limit = $overnight && $this->nightsUsed($tenancy, $on) + $visitor->nights > (int) $settings->overnight_limit_per_month;
        $visitor->save();

        return $visitor;
    }

    /**
     * Staff log someone who arrived unregistered: checked in at once.
     *
     * @param  array{tenancy_id: int, name: string, contact?: ?string, overnight?: bool, nights?: ?int}  $data
     */
    public function walkIn(Property $property, User $by, array $data): Visitor
    {
        $tenancy = Tenancy::current()->where('property_id', $property->id)->find($data['tenancy_id']);
        if (! $tenancy) {
            throw ValidationException::withMessages(['tenancy_id' => 'Choose someone who stays here now.']);
        }
        $today = ManilaDate::today();
        $overnight = (bool) ($data['overnight'] ?? false);
        $nights = $overnight ? max(1, (int) ($data['nights'] ?? 1)) : 0;

        return Visitor::create([
            'property_id' => $property->id,
            'tenancy_id' => $tenancy->id,
            'registered_by' => $by->id,
            'name' => trim($data['name']),
            'contact' => isset($data['contact']) ? trim($data['contact']) ?: null : null,
            'visit_on' => $today->toDateString(),
            'overnight' => $overnight,
            'nights' => $nights,
            'status' => VisitorStatus::CheckedIn,
            'is_walk_in' => true,
            'over_limit' => $overnight && $this->nightsUsed($tenancy, $today) + $nights > (int) $this->settings($tenancy)->overnight_limit_per_month,
            'checked_in_at' => now(),
        ]);
    }

    public function approve(Visitor $visitor, User $by): Visitor
    {
        $this->expect($visitor, [VisitorStatus::Pending], 'Only an overnight stay waiting for approval can be approved.');
        // Nights approved in the meantime may have used up the month.
        $over = $this->nightsUsed($visitor->tenancy, $visitor->visit_on) + $visitor->nights > (int) $this->settings($visitor->tenancy)->overnight_limit_per_month;
        $visitor->update(['status' => VisitorStatus::Approved, 'decided_by' => $by->id, 'decided_at' => now(), 'over_limit' => $over]);

        return $visitor;
    }

    public function decline(Visitor $visitor, User $by, string $reason): Visitor
    {
        $this->expect($visitor, [VisitorStatus::Pending], 'Only an overnight stay waiting for approval can be declined.');
        $visitor->update(['status' => VisitorStatus::Declined, 'decided_by' => $by->id, 'decided_at' => now(), 'decline_reason' => $reason]);

        return $visitor;
    }

    public function checkIn(Visitor $visitor): Visitor
    {
        if (! $visitor->status->canCheckIn()) {
            throw ValidationException::withMessages(['status' => $visitor->status === VisitorStatus::Pending
                ? 'This overnight stay is not approved yet.'
                : 'This visitor cannot be checked in.']);
        }
        $visitor->update(['status' => VisitorStatus::CheckedIn, 'checked_in_at' => now()]);

        return $visitor;
    }

    public function checkOut(Visitor $visitor): Visitor
    {
        $this->expect($visitor, [VisitorStatus::CheckedIn], 'Only a visitor who is checked in can be checked out.');
        $visitor->update(['status' => VisitorStatus::CheckedOut, 'checked_out_at' => now()]);

        return $visitor;
    }

    /** The tenant takes it back before the visitor arrives and before the day passes. */
    public function cancel(Visitor $visitor): Visitor
    {
        $this->expect($visitor, [VisitorStatus::Pending, VisitorStatus::Registered, VisitorStatus::Approved], 'This visit can no longer be cancelled.');
        if ($visitor->visit_on->lt(ManilaDate::today())) {
            throw ValidationException::withMessages(['status' => 'This visit can no longer be cancelled.']);
        }
        $visitor->update(['status' => VisitorStatus::Cancelled]);

        return $visitor;
    }

    /** Overnight nights already counted for this stay in $day's month (pending, declined and cancelled ones do not count). */
    public function nightsUsed(Tenancy $tenancy, CarbonImmutable $day, ?int $exceptId = null): int
    {
        return (int) Visitor::where('tenancy_id', $tenancy->id)->where('overnight', true)
            ->whereIn('status', VisitorStatus::countedValues())
            ->whereBetween('visit_on', [$day->startOfMonth()->toDateString(), $day->endOfMonth()->toDateString()])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->sum('nights');
    }

    /** Delete records KEEP_DAYS after the visit (D4 privacy). Returns how many. */
    public function purge(CarbonImmutable $day): int
    {
        return Visitor::whereDate('visit_on', '<', $day->subDays(Visitor::KEEP_DAYS)->toDateString())->delete();
    }

    private function settings(Tenancy $tenancy): PropertySettings
    {
        return $tenancy->property->settings ?? new PropertySettings(PropertySettings::DEFAULTS);
    }

    /** @param  list<VisitorStatus>  $allowed */
    private function expect(Visitor $visitor, array $allowed, string $message): void
    {
        if (! in_array($visitor->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}
