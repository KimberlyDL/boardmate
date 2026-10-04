<?php

namespace App\Http\Resources\Listings;

use App\Enums\FilePurpose;
use App\Enums\RentalMode;
use App\Enums\UtilityMethod;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\UtilityAccount;
use App\Services\Files\Contracts\FileService;
use App\Services\Pricing\PriceBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A property as the PUBLIC sees it (Dorm Finder, F1). Never includes owner
 * contacts, caretakers, tenants, internal settings or the street address
 * (unless `showStreet`, i.e. the viewer holds a reservation there).
 *
 * @mixin Property
 */
class ListingResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detail = false, private readonly bool $showStreet = false)
    {
        parent::__construct($resource);
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
            'rental_mode' => $this->rental_mode->value,
            'barangay' => $this->barangay,
            'city' => $this->city,
            'province' => $this->province,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'who_can_apply' => $this->who_can_apply,
            'price_from_centavos' => $this->price_from_centavos !== null ? (int) $this->price_from_centavos : null,
            'availability' => [
                'available' => $bookable->count(),
                'total' => $this->units->count(),
                'label' => $this->rental_mode === RentalMode::Whole
                    ? 'Whole property for up to '.($this->units->first()?->capacity ?? 1)
                    : "{$bookable->count()} of {$this->units->count()} bedspaces available",
            ],
            'included_utilities' => $included,
            'cover_photo_url' => $photos->first() ? $files->url($photos->first()->path, FilePurpose::ListingPhoto) : null,
            'owner_name' => $this->owner->ownerDisplayName(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];

        if (! $this->detail) {
            return $summary;
        }

        $settings = $this->settings;

        return $summary + [
            'description' => $this->description,
            'street' => $this->showStreet ? $this->street : null,
            'photos' => $photos->map(fn ($p) => $files->url($p->path, FilePurpose::ListingPhoto)),
            'slots' => $bookable->values()->map(fn (RentableUnit $u) => [
                'id' => $u->id,
                'label' => $u->label,
                'kind' => $u->kind->value,
                'capacity' => $u->capacity,
                'rent_centavos' => $prices->amountOn($u),
            ]),
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
            'house_rules_summary' => null, // F10
        ];
    }
}
