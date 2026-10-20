<?php

namespace App\Http\Resources\Listings;

use App\Enums\FilePurpose;
use App\Enums\RentalMode;
use App\Enums\UnitKind;
use App\Enums\UnitStatus;
use App\Enums\UtilityMethod;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\Room;
use App\Models\UtilityAccount;
use App\Services\Files\Contracts\FileService;
use App\Services\Pricing\PriceBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A property as the PUBLIC sees it (Dorm Finder, F1). Never includes owner
 * contacts, caretakers, tenants or who lives in which room, internal
 * settings or the street address (unless `showStreet`, i.e. the viewer holds
 * a reservation there).
 *
 * @mixin Property
 */
class ListingResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detail = false, private readonly bool $showStreet = false)
    {
        parent::__construct($resource);
    }

    /**
     * "Whole room for up to 4", "2 of 5 bedspaces available", or both for a
     * property that mixes whole rooms and bedspace rooms.
     *
     * @param  Collection<int, RentableUnit>  $bookable
     */
    private function availabilityLabel(Collection $bookable): string
    {
        $places = $this->units->where('is_whole_option', false);
        $whole = $places->where('kind', UnitKind::Whole);
        $beds = $places->where('kind', UnitKind::Bedspace);
        $parts = [];
        if ($whole->isNotEmpty()) {
            $parts[] = $whole->count() === 1
                ? 'Whole room for up to '.$whole->first()->capacity
                : $bookable->where('kind', UnitKind::Whole)->count().' of '.$whole->count().' whole rooms available';
        }
        if ($beds->isNotEmpty()) {
            $parts[] = $bookable->where('kind', UnitKind::Bedspace)->count().' of '.$beds->count().' bedspaces available';
        }

        return implode(' · ', $parts);
    }

    /**
     * A room as the public sees it on "Rooms & availability": its status,
     * price and free places, never who lives there.
     *
     * @return array<string, mixed>
     */
    private function publicRoom(Room $room, PriceBook $prices): array
    {
        $roomUnits = $this->units->where('room_id', $room->id);
        $option = $roomUnits->firstWhere('is_whole_option', true);
        $units = $roomUnits->where('is_whole_option', false)->values();
        $free = $units->filter(fn (RentableUnit $u) => $u->isAvailable());
        $status = match (true) {
            $free->isNotEmpty() || $option?->isAvailable() => 'available',
            $units->contains(fn (RentableUnit $u) => $u->status === UnitStatus::Available && $u->not_ready) => 'not_ready',
            default => 'full',
        };
        $rents = ($free->isNotEmpty() ? $free : $units)->map(fn (RentableUnit $u) => $prices->amountOn($u))->filter(fn ($r) => $r !== null);
        $whole = $room->rental_mode === RentalMode::Whole;
        $capacity = $whole ? (int) $units->first()?->capacity : $units->count();

        return [
            'id' => $room->id,
            'label' => $room->label(),
            'code' => $room->code(),
            'door_no' => $room->door_no,
            'floor' => $room->floor,
            'rental_mode' => $room->rental_mode->value,
            'rental_mode_label' => $room->rental_mode->label(),
            'who_can_stay' => $room->who_can_stay->value,
            'who_can_stay_label' => $room->who_can_stay->label(),
            'status' => $status,
            'available' => $free->count(),
            'total' => $units->count(),
            'capacity' => $capacity,
            'rent_from_centavos' => $rents->isEmpty() ? null : (int) $rents->min(),
            'availability_label' => $whole
                ? 'Whole room for up to '.$capacity
                : $free->count().' of '.$units->count().' bedspaces available',
            // A bedspace room that can also be rented as one room, while every bedspace is free.
            'whole_option' => $option ? [
                'available' => $option->isAvailable(),
                'rent_centavos' => $prices->amountOn($option),
                'capacity' => $option->capacity,
            ] : null,
        ];
    }

    public function toArray(Request $request): array
    {
        $files = app(FileService::class);
        $prices = app(PriceBook::class);
        $bookable = $this->units->filter(fn (RentableUnit $u) => $u->isAvailable());
        $photos = $this->photos->sortBy([['is_cover', 'desc'], ['sort_order', 'asc']])->values();
        $included = $this->utilityAccounts
            ->filter(fn (UtilityAccount $a) => $a->method === UtilityMethod::Included)
            ->map(fn (UtilityAccount $a) => $a->type->value)->unique()->values();

        $summary = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'rental_mode' => $this->rentalModeSummary(),
            'barangay' => $this->barangay,
            'city' => $this->city,
            'province' => $this->province,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'who_can_apply' => $this->who_can_apply,
            'price_from_centavos' => $this->price_from_centavos !== null ? (int) $this->price_from_centavos : null,
            'is_full' => $bookable->isEmpty(),
            // Straight-line metres from the search's `near` point, when given.
            'distance_m' => isset($this->distance_m) ? (int) round((float) $this->distance_m) : null,
            'availability' => [
                'available' => $bookable->where('is_whole_option', false)->count(),
                'total' => $this->units->where('is_whole_option', false)->count(),
                'label' => $this->availabilityLabel($bookable),
            ],
            'included_utilities' => $included,
            'cover_photo_url' => $photos->first() ? $files->url($photos->first()->thumbOrLargePath(), FilePurpose::ListingPhoto) : null,
            'owner_name' => $this->owner->ownerDisplayName(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];

        if (! $this->detail) {
            return $summary;
        }

        $settings = $this->settings;
        $prices->preload($this->units);
        $prices->preload($this->utilityAccounts);
        $rooms = $this->rooms->each(fn (Room $r) => $r->setRelation('property', $this->resource))->keyBy('id');

        return $summary + [
            'description' => $this->description,
            'street' => $this->showStreet ? $this->street : null,
            'photos' => $photos->map(fn ($p) => $files->url($p->path, FilePurpose::ListingPhoto)),
            'photo_thumbs' => $photos->map(fn ($p) => $files->url($p->thumbOrLargePath(), FilePurpose::ListingPhoto)),
            'slots' => $bookable->values()->map(fn (RentableUnit $u) => [
                'id' => $u->id,
                'label' => $u->label,
                'kind' => $u->kind->value,
                'room' => $rooms->has($u->room_id) ? [
                    'id' => $u->room_id,
                    'code' => $rooms[$u->room_id]->code(),
                    'door_no' => $rooms[$u->room_id]->door_no,
                    'label' => $rooms[$u->room_id]->label(),
                    'floor' => $rooms[$u->room_id]->floor,
                    'who_can_stay' => $rooms[$u->room_id]->who_can_stay->value,
                    'who_can_stay_label' => $rooms[$u->room_id]->who_can_stay->label(),
                ] : null,
                'capacity' => $u->capacity,
                'rent_centavos' => $prices->amountOn($u),
            ]),
            'rooms' => $rooms->values()->map(fn (Room $room) => $this->publicRoom($room, $prices)),
            'utilities' => $this->utilityAccounts->map(fn (UtilityAccount $a) => [
                'type' => $a->type->value,
                'name' => $a->name,
                'method' => $a->method->value,
                'method_label' => $a->method->label(),
                'billed_by' => $a->billed_by->value,
                'amount_centavos' => $a->method->hasFixedAmount() ? $prices->amountOn($a) : null,
            ])->values(),
            'deposit' => [
                'rule' => $settings?->deposit_rule?->value,
                'label' => $settings?->deposit_rule?->label(),
                'fixed_centavos' => $settings?->deposit_fixed_centavos,
            ],
            'curfew_time' => $settings?->curfew_time ? substr((string) $settings->curfew_time, 0, 5) : null,
            // D3: the rules in force, for anyone thinking of applying.
            'house_rules' => $this->currentHouseRules ? [
                'version' => $this->currentHouseRules->version,
                'published_at' => $this->currentHouseRules->published_at?->toIso8601String(),
                'rules' => $this->currentHouseRules->rules->map(fn ($r) => [
                    'category' => $r->category->value,
                    'category_label' => $r->category->label(),
                    'body' => $r->body,
                    'is_auto' => (bool) $r->is_auto,
                ])->values(),
            ] : null,
        ];
    }
}
