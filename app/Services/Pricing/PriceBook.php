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
use WeakMap;

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
    /**
     * Filled by preload(): model => today's and the next rule, for the Manila
     * day they were read. Kept off the model itself (no fake relations to
     * serialize), and dropped automatically with the model.
     *
     * @var WeakMap<Model, array{day: string, current: ?PriceRule, upcoming: ?PriceRule}>|null
     */
    private static ?WeakMap $preloaded = null;

    /** Price in force on a day (default today, Manila), or null if none set yet. */
    public function amountOn(Model $priceable, DateTimeInterface|string|null $day = null): ?int
    {
        return $this->ruleOn($priceable, $day)?->amount_centavos;
    }

    public function ruleOn(Model $priceable, DateTimeInterface|string|null $day = null): ?PriceRule
    {
        if ($day === null && ($cached = $this->cached($priceable))) {
            return $cached['current'];
        }

        $day = $day ? ManilaDate::parse($day)->toDateString() : ManilaDate::today()->toDateString();

        return $priceable->priceRules()
            ->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->first();
    }

    /** A price already set to start later (e.g. a scheduled increase). */
    public function upcoming(Model $priceable): ?PriceRule
    {
        if ($cached = $this->cached($priceable)) {
            return $cached['upcoming'];
        }

        return $priceable->priceRules()
            ->where('effective_from', '>', ManilaDate::today()->toDateString())
            ->reorder('effective_from')
            ->first();
    }

    /**
     * Today's price and the next scheduled one for many priceables of one
     * kind, in one query. After this, amountOn()/ruleOn() without a day and
     * upcoming() answer from memory (lists stay at a fixed query count).
     *
     * @param  iterable<Model>  $priceables
     */
    public function preload(iterable $priceables): void
    {
        $models = collect($priceables)->filter()->reject(fn (Model $m) => $this->cached($m) !== null)->values();
        if ($models->isEmpty()) {
            return;
        }

        $today = ManilaDate::today()->toDateString();
        self::$preloaded ??= new WeakMap;
        $rules = PriceRule::query()
            ->where('priceable_type', $models->first()->getMorphClass())
            ->whereIn('priceable_id', $models->map->getKey()->all())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy('priceable_id');

        foreach ($models as $model) {
            $own = $rules->get($model->getKey(), collect());
            self::$preloaded[$model] = [
                'day' => $today,
                'current' => $own->filter(fn (PriceRule $r) => $r->effective_from->toDateString() <= $today)->last(),
                'upcoming' => $own->first(fn (PriceRule $r) => $r->effective_from->toDateString() > $today),
            ];
        }
    }

    /** @return array{day: string, current: ?PriceRule, upcoming: ?PriceRule}|null preloaded values, if still for today */
    private function cached(Model $priceable): ?array
    {
        $entry = self::$preloaded[$priceable] ?? null;

        return $entry && $entry['day'] === ManilaDate::today()->toDateString() ? $entry : null;
    }

    /** @return array<int, int|null> priceable id => amount in force today */
    public function amountsOn(iterable $priceables): array
    {
        $models = collect($priceables)->filter()->values();
        $this->preload($models);

        return $models->mapWithKeys(fn (Model $m) => [$m->getKey() => $this->amountOn($m)])->all();
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

        // Anything preloaded for this priceable (any copy of it) is about to be out of date.
        $stale = [];
        foreach (self::$preloaded ?? [] as $model => $entry) {
            if ($model->is($priceable)) {
                $stale[] = $model;
            }
        }
        foreach ($stale as $model) {
            unset(self::$preloaded[$model]);
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

            // A bill already uses a price from that date on: it cannot be replaced.
            $locked = (clone $rules)->whereNotNull('locked_at')
                ->where('effective_from', '>=', $from->toDateString())
                ->reorder('effective_from', 'desc')
                ->first();
            if ($locked) {
                throw new InvalidArgumentException('A bill already uses the price from '
                    .$locked->effective_from->format('M j, Y').'. Set the change to start after that.');
            }

            // Replace scheduled prices that start on or after the new date and are not in force yet.
            (clone $rules)->where('effective_from', '>=', $from->toDateString())
                ->where('effective_from', '>', $today->toDateString())
                ->whereNull('locked_at')
                ->delete();

            // A price set today for today can be corrected the same day (unless a bill used it).
            (clone $rules)->where('effective_from', $from->toDateString())
                ->where('created_at', '>=', $today->toDateTimeString())
                ->whereNull('locked_at')
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

    /**
     * Billing calls this when a bill is issued from these rules, so the price
     * the bill shows can never be deleted or replaced afterwards.
     *
     * @param  PriceRule|iterable<PriceRule>  $rules
     */
    public function lock(PriceRule|iterable $rules): int
    {
        $ids = collect($rules instanceof PriceRule ? [$rules] : $rules)->map(fn (PriceRule $r) => $r->id);

        return PriceRule::whereKey($ids)->whereNull('locked_at')->update(['locked_at' => now(), 'updated_at' => now()]);
    }
}
