<?php

namespace App\Services\Pricing;

use App\Models\PriceRule;
use App\Models\User;
use App\Support\ManilaDate;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pricing module (Billing guide S4): a price is never overwritten. Setting a
 * new price closes the rule in force the day before the new effective date
 * and opens a new rule, so past periods keep the price they had.
 *
 * Works for anything with a `priceRules()` morphMany and a `property_id`
 * (unit rent, a utility account's fixed amount).
 */
class PriceBook
{
    /** Price in force on a day (default today, Manila), or null if none set yet. */
    public function amountOn(Model $priceable, DateTimeInterface|string|null $day = null): ?int
    {
        return $this->ruleOn($priceable, $day)?->amount_centavos;
    }

    public function ruleOn(Model $priceable, DateTimeInterface|string|null $day = null): ?PriceRule
    {
        $day = $day ? ManilaDate::parse($day)->toDateString() : ManilaDate::today()->toDateString();

        return $priceable->priceRules()
            ->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->first();
    }

    /** A price already set to start later (e.g. a scheduled increase). */
    public function upcoming(Model $priceable): ?PriceRule
    {
        return $priceable->priceRules()
            ->where('effective_from', '>', ManilaDate::today()->toDateString())
            ->reorder('effective_from')
            ->first();
    }

    /** @return Collection<int, PriceRule> newest first */
    public function history(Model $priceable): Collection
    {
        return $priceable->priceRules()->with('setter:id,name')->get();
    }

    /**
     * Set a price from a date (today or later; no back-dating). A price that
     * was scheduled for that date or later, and has not started yet, is
     * replaced; prices already in force are only ever closed, never changed.
     * Setting the price that is already in force returns that rule unchanged
     * (check `wasRecentlyCreated` to know whether anything changed).
     */
    public function set(Model $priceable, int $amountCentavos, DateTimeInterface|string|null $effectiveFrom, ?User $setBy): PriceRule
    {
        if ($amountCentavos < 0) {
            throw new InvalidArgumentException('A price cannot be negative.');
        }

        $today = ManilaDate::today();
        $from = $effectiveFrom ? ManilaDate::parse($effectiveFrom)->startOfDay() : $today;
        if ($from->lessThan($today)) {
            throw new InvalidArgumentException('A price change cannot start in the past.');
        }

        return DB::transaction(function () use ($priceable, $amountCentavos, $from, $today, $setBy) {
            $rules = $priceable->priceRules();

            // Same price already in force from that date, nothing scheduled after: no change.
            $inForce = $this->ruleOn($priceable, $from);
            if ($inForce && $inForce->amount_centavos === $amountCentavos
                && ! (clone $rules)->where('effective_from', '>', $from->toDateString())->exists()) {
                return $inForce;
            }

            // Replace scheduled prices that start on or after the new date and are not in force yet.
            (clone $rules)->where('effective_from', '>=', $from->toDateString())
                ->where('effective_from', '>', $today->toDateString())
                ->delete();

            // A price set today for today can be corrected the same day.
            (clone $rules)->where('effective_from', $from->toDateString())
                ->where('created_at', '>=', $today->toDateTimeString())
                ->delete();

            // Close whatever would still be in force on the new date.
            (clone $rules)->where('effective_from', '<', $from->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->update(['effective_to' => $from->subDay()->toDateString(), 'updated_at' => now()]);

            return $priceable->priceRules()->create([
                'property_id' => $priceable->property_id,
                'amount_centavos' => $amountCentavos,
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'set_by' => $setBy?->id,
            ]);
        });
    }
}
