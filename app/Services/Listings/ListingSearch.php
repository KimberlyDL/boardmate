<?php

namespace App\Services\Listings;

use App\Enums\UnitStatus;
use App\Models\PriceRule;
use App\Models\Property;
use App\Support\ManilaDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Dorm Finder (F1): the public search. Every property on the Finder
 * (Property::scopeOnFinder) is found, full ones too; places with a free unit
 * come first. "Price from" is the cheapest current rent among bookable units,
 * read from the effective-dated price rules. With `near`, each place gets its
 * straight-line distance in metres from that point.
 */
class ListingSearch
{
    public const INCLUDABLE = ['water', 'electricity', 'internet'];

    public const MAX_PER_PAGE = 200;

    /** Great-circle distance in metres from (?, ?) = (lat, lat, lng) to the property's pin. */
    private const DISTANCE_SQL = '6371000 * 2 * asin(sqrt(power(sin(radians(properties.latitude - ?) / 2), 2)'
        .' + cos(radians(?)) * cos(radians(properties.latitude)) * power(sin(radians(properties.longitude - ?) / 2), 2)))';

    /**
     * @param  array{q?: string, min_price?: int, max_price?: int, rental_mode?: string, includes?: list<string>,
     *               who?: string, who_can_stay?: string, available?: bool, near?: array{0: float, 1: float},
     *               bbox?: array{0: float, 1: float, 2: float, 3: float}, sort?: string}  $filters
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $price = self::priceFromSubquery();
        $near = $filters['near'] ?? null;

        return Property::query()
            ->onFinder()
            ->select('properties.*')
            ->selectSub($price, 'price_from_centavos')
            ->selectSub(self::hasFreeUnitSubquery(), 'has_free_unit')
            ->when($near, fn (Builder $q) => $q->selectRaw(self::DISTANCE_SQL.' as distance_m', [$near[0], $near[0], $near[1]]))
            ->when($filters['available'] ?? false, fn (Builder $q) => $q->whereHas('units', fn (Builder $u) => $u->free()))
            ->when($filters['q'] ?? null, function (Builder $q, string $text) {
                $term = '%'.mb_strtolower(trim($text)).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(properties.city) like ?', [$term])
                    ->orWhereRaw('lower(properties.barangay) like ?', [$term])
                    ->orWhereRaw('lower(properties.province) like ?', [$term])
                    ->orWhereRaw('lower(properties.name) like ?', [$term]));
            })
            ->when(isset($filters['min_price']), fn (Builder $q) => $q->where($price, '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $q) => $q->where($price, '<=', $filters['max_price']))
            ->when($filters['rental_mode'] ?? null, fn (Builder $q, string $mode) => $q->whereHas('rooms', fn (Builder $r) => $r->where('rental_mode', $mode)))
            ->when($filters['who_can_stay'] ?? null, fn (Builder $q, string $who) => $q->whereHas('rooms', fn (Builder $r) => $r->whereIn('who_can_stay', [$who, 'any'])))
            ->when($filters['includes'] ?? [], function (Builder $q, array $types) {
                foreach ($types as $type) {
                    $q->whereHas('utilityAccounts', fn (Builder $u) => $u->where('type', $type)->where('method', 'included'));
                }
            })
            ->when($filters['who'] ?? null, fn (Builder $q, string $who) => $q->whereRaw('lower(who_can_apply) like ?', ['%'.mb_strtolower($who).'%']))
            ->when($filters['bbox'] ?? null, function (Builder $q, array $box) {
                [$south, $west, $north, $east] = $box;
                $q->whereBetween('latitude', [$south, $north])->whereBetween('longitude', [$west, $east]);
            })
            ->orderByDesc('has_free_unit')
            ->when($filters['sort'] ?? 'newest', fn (Builder $q, string $sort) => match ($sort) {
                'price' => $q->orderByRaw('price_from_centavos asc nulls last'),
                'nearest' => $q->orderBy('distance_m'),
                default => $q->orderByDesc('published_at'),
            })
            ->orderBy('properties.id')
            ->with(['photos', 'rooms', 'units', 'utilityAccounts', 'owner.ownerProfile'])
            ->paginate(min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * A single property on the Finder, or null (unpublished, hidden owner…).
     * With `$bookable`, only when it has a free unit (for applying).
     */
    public function find(int $id, bool $bookable = false): ?Property
    {
        return Property::query()
            ->when($bookable, fn (Builder $q) => $q->listable(), fn (Builder $q) => $q->onFinder())
            ->select('properties.*')
            ->selectSub(self::priceFromSubquery(), 'price_from_centavos')
            ->with(['photos', 'rooms', 'units', 'utilityAccounts', 'settings', 'owner.ownerProfile', 'currentHouseRules.rules'])
            ->find($id);
    }

    /** Cheapest rent in force today among the property's bookable units. */
    public static function priceFromSubquery(): QueryBuilder
    {
        $today = ManilaDate::today()->toDateString();

        return PriceRule::query()
            ->toBase()
            ->selectRaw('min(price_rules.amount_centavos)')
            ->join('rentable_units', 'rentable_units.id', '=', 'price_rules.priceable_id')
            ->where('price_rules.priceable_type', 'rentable_unit')
            ->whereColumn('rentable_units.property_id', 'properties.id')
            ->whereNull('rentable_units.deleted_at')
            ->where('rentable_units.status', UnitStatus::Available->value)
            ->where('rentable_units.not_ready', false)
            ->where('rentable_units.blocked', false)
            ->where('price_rules.effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('price_rules.effective_to')->orWhere('price_rules.effective_to', '>=', $today));
    }

    /** 1 when the property has a free, ready unit, else 0 (for "free first" ordering). */
    private static function hasFreeUnitSubquery(): QueryBuilder
    {
        return DB::table('rentable_units')
            ->selectRaw('count(*) > 0')
            ->whereColumn('rentable_units.property_id', 'properties.id')
            ->whereNull('rentable_units.deleted_at')
            ->where('rentable_units.status', UnitStatus::Available->value)
            ->where('rentable_units.not_ready', false)
            ->where('rentable_units.blocked', false);
    }
}
