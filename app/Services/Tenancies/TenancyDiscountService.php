<?php

namespace App\Services\Tenancies;

use App\Enums\AuditEvent;
use App\Enums\DiscountKind;
use App\Enums\NotificationEvent;
use App\Models\Tenancy;
use App\Models\TenancyDiscount;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Pricing\PriceBook;
use App\Support\ManilaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A tenancy's optional "Agreed rate" discount (System Design C3): manual,
 * never automatic, and effective-dated like a price.
 *
 * - A change starts today or later (no back-dating).
 * - Past rows are never changed: the one in force is closed the day before.
 * - A scheduled discount that has not started is replaced, not stacked, and a
 *   discount set today can be corrected the same day.
 * - A discount a bill used is locked (`locked_at`): it is only ever closed.
 * - Saving the discount already in force changes nothing.
 *
 * Billing applies the discount in force on a period's start date.
 */
class TenancyDiscountService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PriceBook $prices,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{kind: string, value: int}  $discount
     * @return array{0: TenancyDiscount, 1: bool} the discount from that date, and whether anything changed
     *
     * @throws ValidationException
     */
    public function set(Tenancy $tenancy, array $discount, ?string $effectiveFrom, User $actor): array
    {
        $from = $this->startDate($effectiveFrom);
        $kind = DiscountKind::from($discount['kind']);
        $value = (int) $discount['value'];
        $this->assertValid($tenancy, $kind, $value, $from->toDateString());

        [$row, $before] = DB::transaction(function () use ($tenancy, $kind, $value, $from, $actor) {
            $this->lock($tenancy);
            $rows = $tenancy->discounts();
            $today = ManilaDate::today();

            $inForce = $this->inForceOn($tenancy, $from->toDateString());
            if ($inForce && $inForce->kind === $kind && $inForce->value === $value
                && ! (clone $rows)->where('effective_from', '>', $from->toDateString())->exists()) {
                return [$inForce, null];
            }

            $this->assertNothingLockedFrom($tenancy, $from->toDateString());

            // Replace scheduled discounts that start on or after the new date and are not in force yet.
            (clone $rows)->where('effective_from', '>=', $from->toDateString())
                ->where('effective_from', '>', $today->toDateString())
                ->whereNull('locked_at')
                ->delete();

            // A discount set today for today can be corrected the same day.
            (clone $rows)->where('effective_from', $from->toDateString())
                ->where('created_at', '>=', $today->toDateTimeString())
                ->whereNull('locked_at')
                ->delete();

            $before = $this->inForceOn($tenancy, $from->subDay()->toDateString());
            $this->closeBefore($tenancy, $from->toDateString());

            $row = $tenancy->discounts()->create([
                'kind' => $kind,
                'value' => $value,
                'effective_from' => $from->toDateString(),
                'set_by' => $actor->id,
            ]);

            return [$row, $before ?? false];
        });

        $changed = $before !== null;
        if ($changed) {
            $tenancy->loadMissing('property.owner', 'tenant');
            $this->audit->record(AuditEvent::DiscountSet, $tenancy,
                ['discount' => [$before ? $this->describe($before) : null, $this->describe($row)]],
                owner: $tenancy->property->owner, actor: $actor,
                note: "{$tenancy->property->name} · {$tenancy->tenant->name} from ".$from->format('M j, Y'));
            $this->notifications->send($tenancy->tenant, NotificationEvent::DiscountChanged, [
                'property_name' => $tenancy->property->name,
                'change' => 'set',
                'from' => $from->format('M j, Y'),
                'description' => $this->describe($row).' your rent',
            ]);
        }

        return [$row, $changed];
    }

    /**
     * Take the discount away from a date (default today): the one in force is
     * closed the day before, and scheduled ones are removed.
     *
     * @throws ValidationException when there is no discount to end
     */
    public function end(Tenancy $tenancy, ?string $effectiveFrom, User $actor): void
    {
        $from = $this->startDate($effectiveFrom);

        $ended = DB::transaction(function () use ($tenancy, $from) {
            $this->lock($tenancy);

            $before = $this->inForceOn($tenancy, $from->toDateString());
            $scheduled = $tenancy->discounts()->where('effective_from', '>=', $from->toDateString())->exists();
            if (! $before && ! $scheduled) {
                throw ValidationException::withMessages(['discount' => 'There is no discount to end from that date.']);
            }

            $this->assertNothingLockedFrom($tenancy, $from->toDateString());
            $tenancy->discounts()->where('effective_from', '>=', $from->toDateString())->delete();
            $this->closeBefore($tenancy, $from->toDateString());

            return $before;
        });

        $tenancy->loadMissing('property.owner', 'tenant');
        $this->audit->record(AuditEvent::DiscountEnded, $tenancy,
            ['discount' => [$ended ? $this->describe($ended) : 'Scheduled discount', null]],
            owner: $tenancy->property->owner, actor: $actor,
            note: "{$tenancy->property->name} · {$tenancy->tenant->name} from ".$from->format('M j, Y'));
        $this->notifications->send($tenancy->tenant, NotificationEvent::DiscountChanged, [
            'property_name' => $tenancy->property->name,
            'change' => 'ended',
            'from' => $from->format('M j, Y'),
            'description' => '',
        ]);
    }

    /** The discount in force on a day (calendar date, Manila), if any. */
    public function inForceOn(Tenancy $tenancy, string $day): ?TenancyDiscount
    {
        return $tenancy->discounts()->inForceOn($day)->first();
    }

    /** Short text for the audit log: "₱500.00 off" or "10% off". */
    public function describe(TenancyDiscount $discount): string
    {
        return match ($discount->kind) {
            DiscountKind::Fixed => Money::format($discount->value).' off',
            DiscountKind::Percent => rtrim(rtrim(number_format($discount->value / 100, 2), '0'), '.').'% off',
        };
    }

    private function startDate(?string $effectiveFrom): CarbonImmutable
    {
        $today = ManilaDate::today();
        $from = $effectiveFrom ? ManilaDate::parse($effectiveFrom)->startOfDay() : $today;
        if ($from->lt($today)) {
            throw ValidationException::withMessages(['effective_from' => 'A discount change cannot start in the past.']);
        }

        return $from;
    }

    private function assertValid(Tenancy $tenancy, DiscountKind $kind, int $value, string $from): void
    {
        if ($kind === DiscountKind::Percent && ($value < 1 || $value > 10000)) {
            throw ValidationException::withMessages(['value' => 'A percent discount is between 0.01% and 100%.']);
        }
        if ($kind === DiscountKind::Fixed) {
            $rent = $this->prices->amountOn($tenancy->unit, $from);
            if ($value < 1 || ($rent !== null && $value > $rent)) {
                throw ValidationException::withMessages(['value' => 'A fixed discount must be more than nothing and not more than the rent.']);
            }
        }
    }

    /** Lock the tenancy and make sure it is still running. */
    private function lock(Tenancy $tenancy): void
    {
        $locked = Tenancy::whereKey($tenancy->id)->lockForUpdate()->firstOrFail();
        if (! Tenancy::current()->whereKey($locked->id)->exists()) {
            throw ValidationException::withMessages(['discount' => 'This tenancy has ended.']);
        }
    }

    private function assertNothingLockedFrom(Tenancy $tenancy, string $from): void
    {
        $locked = $tenancy->discounts()->whereNotNull('locked_at')
            ->where('effective_from', '>=', $from)
            ->reorder('effective_from', 'desc')
            ->first();

        if ($locked) {
            throw ValidationException::withMessages([
                'effective_from' => 'A bill already uses the discount from '.$locked->effective_from->format('M j, Y').'. Make the change start after that.',
            ]);
        }
    }

    /** Close whatever is still in force on or after $from, the day before it. */
    private function closeBefore(Tenancy $tenancy, string $from): void
    {
        $tenancy->discounts()
            ->where('effective_from', '<', $from)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->update(['effective_to' => ManilaDate::parse($from)->subDay()->toDateString(), 'updated_at' => now()]);
    }
}
