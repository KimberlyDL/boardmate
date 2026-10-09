<?php

namespace App\Services\Listings;

use App\Enums\UnitStatus;
use App\Models\PriceRule;
use App\Models\Property;
use App\Support\ManilaDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Dorm Finder (F1): the public search. Only listable properties
 * (Property::scopeListable); "price from" is the cheapest current rent among
 * bookable units, read from the effective-dated price rules.
 */
class ListingSearch
{
    public const INCLUDABLE = ['water', 'electricity', 'internet'];

    /**
     * @param  array{q?: string, min_price?: int, max_price?: int, rental_mode?: string, includes?: list<string>,
     *               who?: string, bbox?: array{0: float, 1: float, 2: float, 3: float}, sort?: string}  $filters
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $price = self::priceFromSubquery();

        return Property::query()
            ->listable()
            ->select('properties.*')
            ->selectSub($price, 'price_from_centavos')
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
            ->when(($filters['sort'] ?? 'newest') === 'price',
                fn (Builder $q) => $q->orderByRaw('price_from_centavos asc nulls last'),
                fn (Builder $q) => $q->orderByDesc('published_at'))
            ->orderBy('properties.id')
            ->with(['photos', 'rooms', 'building', 'units', 'utilityAccounts', 'owner.ownerProfile'])
            ->paginate($perPage);
    }

    /** A single listable property, or null (unpublished, hidden owner, nothing bookable…). */
    public function find(int $id): ?Property
    {
        return Property::query()
            ->listable()
            ->select('properties.*')
            ->selectSub(self::priceFromSubquery(), 'price_from_centavos')
            ->with(['photos', 'rooms', 'building', 'units', 'utilityAccounts', 'settings', 'owner.ownerProfile'])
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
            ->where('price_rules.effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('price_rules.effective_to')->orWhere('price_rules.effective_to', '>=', $today));
    }
}
