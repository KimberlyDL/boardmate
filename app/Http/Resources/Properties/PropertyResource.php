<?php

namespace App\Http\Resources\Properties;

use App\Authorization\PropertyAccess;
use App\Enums\CaretakerAccessLevel;
use App\Enums\FilePurpose;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\RentableUnit;
use App\Models\Room;
use App\Services\Files\Contracts\FileService;
use App\Services\Pricing\PriceBook;
use App\Services\Properties\PropertySetup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A property as its owner or caretaker sees it. The list view is a summary;
 * `detail` adds units, utilities, photos, the publish checklist and what the
 * viewer may do (`abilities`), so the app shows only allowed actions.
 *
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    /** @param  string|null  $role  the viewer's role, when already worked out for a list (PropertyAccess::rolesOn) */
    public function __construct($resource, private readonly bool $detail = false, private readonly ?string $role = null)
    {
        parent::__construct($resource);
    }

    /**
     * The property's rooms with their (already prepared) units, for the detail view.
     *
     * @param  Collection<int, RentableUnit>  $units
     * @return Collection<int, Room>
     */
    private function preparedRooms(Collection $units): Collection
    {
        $byRoom = $units->groupBy('room_id');
        $rooms = $this->relationLoaded('rooms') ? $this->rooms : $this->rooms()->get();
        if ($this->detail) {
            // The Rooms tab shows each room's leader and how many people stay.
            $rooms->loadMissing('leader.user');
            $rooms->loadCount(['occupants' => fn ($q) => $q->whereNull('left_on')]);
        }

        return $rooms->each(function (Room $room) use ($byRoom) {
            $room->setRelation('property', $this->resource);
            $room->setRelation('units', $byRoom->get($room->id, collect())->values());
        });
    }

    public function toArray(Request $request): array
    {
        $files = app(FileService::class);
        $user = $request->user();
        $role = $this->role ?? PropertyAccess::roleOn($user, $this->resource);
        $units = $this->relationLoaded('units') ? $this->units : $this->units()->get();
        if ($this->detail) {
            UnitResource::prepare($units);
            app(PriceBook::class)->preload($this->utilityAccounts);
        }
        $cover = $this->relationLoaded('photos')
            ? ($this->photos->firstWhere('is_cover', true) ?? $this->photos->first())
            : ($this->photos()->where('is_cover', true)->first() ?? $this->photos()->first());

        $summary = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'rental_mode' => $this->rentalModeSummary(),
            'rental_mode_label' => $this->rentalModeSummaryLabel(),
            'building' => $this->building ? ['id' => $this->building->id, 'name' => $this->building->name] : null,
            'city' => $this->city,
            'is_published' => $this->is_published,
            'cover_photo_url' => $cover ? $files->url($cover->thumbOrLargePath(), FilePurpose::ListingPhoto) : null,
            'counts' => [
                'rooms' => $this->relationLoaded('rooms') ? $this->rooms->count() : $this->rooms()->count(),
                'units' => $units->count(),
                'available' => $units->filter->isAvailable()->count(),
                'not_ready' => $units->where('not_ready', true)->count(),
            ],
            'my_role' => $role,
            'owner_name' => $this->owner->ownerDisplayName(),
        ];

        if (! $this->detail) {
            return $summary;
        }

        $level = $role === 'owner' ? null : CaretakerAccessLevel::tryFrom((string) $role);

        return $summary + [
            'description' => $this->description,
            'who_can_apply' => $this->who_can_apply,
            'street' => $this->street,
            'barangay' => $this->barangay,
            'province' => $this->province,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'published_at' => $this->published_at?->toIso8601String(),
            'rooms' => RoomResource::collection($this->preparedRooms($units)),
            'units' => UnitResource::collection($units),
            'utility_accounts' => UtilityAccountResource::collection($this->utilityAccounts),
            'photos' => $this->photos->map(fn (PropertyPhoto $p) => [
                'id' => $p->id,
                'url' => $files->url($p->path, FilePurpose::ListingPhoto),
                'thumb_url' => $files->url($p->thumbOrLargePath(), FilePurpose::ListingPhoto),
                'is_cover' => $p->is_cover,
                'sort_order' => $p->sort_order,
            ]),
            'publish_checklist' => app(PropertySetup::class)->publishChecklist($this->resource),
            'abilities' => array_map(fn ($a) => $a->value, PropertyAccess::abilitiesFor($level)),
            'caretakers' => $role === 'owner'
                ? $this->caretakerAssignments()->with('caretaker:id,name,email')->get()->map(fn ($a) => [
                    'caretaker_id' => $a->caretaker_id,
                    'name' => $a->caretaker->name,
                    'email' => $a->caretaker->email,
                    'access_level' => $a->access_level->value,
                    'access_level_label' => $a->access_level->label(),
                ])
                : null,
        ];
    }
}
