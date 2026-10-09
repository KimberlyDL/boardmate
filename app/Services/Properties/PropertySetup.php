<?php

namespace App\Services\Properties;

use App\Enums\RentalMode;
use App\Enums\UnitKind;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Models\RentableUnit;
use App\Models\Room;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating and reshaping a property and its rooms (System Design B1):
 * - every property has at least one room, and each room is rented whole or by bedspace;
 * - a whole room keeps exactly one whole unit; a bedspace room has 1..N bedspaces;
 * - a room's rental mode switches only when none of its units is in use (reserved, occupied…);
 * - every new property starts with the guide's default settings.
 */
class PropertySetup
{
    public const MAX_BEDSPACES = 200;

    public function __construct(private readonly PriceBook $prices) {}

    /**
     * Creates the property with its first room (a house or studio stays a one-room property).
     *
     * @param  array<string, mixed>  $details  fillable property fields
     * @param  array{capacity?: int, rent_centavos?: int|null, count?: int, label_pattern?: string, start_number?: int}  $units
     */
    public function create(User $owner, array $details, RentalMode $mode, array $units, string $type, ?int $floor = null): Property
    {
        return DB::transaction(function () use ($owner, $details, $mode, $units, $type, $floor) {
            $property = new Property($details);
            $property->forceFill([
                'owner_id' => $owner->id,
                'type' => $type,
            ])->save();

            $property->settings()->save(new PropertySettings);
            $this->createRoom($property, $mode, $units, $owner, $floor);

            return $property;
        });
    }

    /**
     * Add a room with its units. The room number is the next free one in the
     * property; the floor is optional.
     *
     * @param  array{capacity?: int, rent_centavos?: int|null, count?: int, label_pattern?: string, start_number?: int}  $units
     */
    public function createRoom(Property $property, RentalMode $mode, array $units, User $by, ?int $floor = null): Room
    {
        return DB::transaction(function () use ($property, $mode, $units, $by, $floor) {
            $number = Room::nextNumberFor($property);
            $room = $property->rooms()->create([
                'number' => $number,
                'floor' => $floor,
                'rental_mode' => $mode,
                'sort_order' => $number,
            ]);
            $room->setRelation('property', $property);
            $this->createUnitsFor($room, $mode, $units, $by);

            return $room;
        });
    }

    /**
     * Archive a room and its units, unless one of them is in use or it is the
     * property's last room. Checked under the property lock that booking
     * approval also takes.
     *
     * @return Collection<int, RentableUnit> the units in use (empty when removed)
     */
    public function deleteRoomIfFree(Room $room): Collection
    {
        return DB::transaction(function () use ($room) {
            $property = $room->property;
            $property->lockForOccupancyChange();
            $blocks = $room->units()->inUse()->orderBy('label')->get();
            if ($blocks->isNotEmpty()) {
                return $blocks;
            }
            if ($property->rooms()->count() <= 1) {
                throw ValidationException::withMessages(['room' => 'A property needs at least one room.']);
            }

            $room->units()->get()->each->delete();
            $room->delete();

            return $blocks;
        });
    }

    /**
     * Unpublish and archive (soft-delete) the property, unless a unit is in
     * use. Checked under the property lock that booking approval also takes.
     *
     * @return Collection<int, RentableUnit> the units in use (empty when deleted)
     */
    public function deleteIfFree(Property $property): Collection
    {
        return DB::transaction(function () use ($property) {
            $property->lockForOccupancyChange();
            $blocks = $property->occupancyBlocks();
            if ($blocks->isEmpty()) {
                $property->forceFill(['is_published' => false])->save();
                $property->delete();
            }

            return $blocks;
        });
    }

    /**
     * Switch a room whole ⇄ bedspaces. Only while none of its units is in
     * use; a person joining or leaving never switches it. Old units are
     * archived (kept with their price history), new ones created.
     *
     * @param  array{capacity?: int, rent_centavos?: int|null, count?: int, label_pattern?: string, start_number?: int}  $units
     */
    public function switchMode(Room $room, RentalMode $mode, array $units, User $by): void
    {
        if ($room->rental_mode === $mode) {
            throw ValidationException::withMessages(['mode' => 'The room already uses this rental mode.']);
        }
        DB::transaction(function () use ($room, $mode, $units, $by) {
            $room->property->lockForOccupancyChange();
            if ($room->hasUnitsInUse()) {
                throw ValidationException::withMessages([
                    'mode' => 'Someone is booked into or living in this room. Switch the rental mode once all its units are free.',
                ]);
            }

            $room->units()->get()->each->delete();
            $room->forceFill(['rental_mode' => $mode])->save();
            $this->createUnitsFor($room, $mode, $units, $by);
        });
    }

    /**
     * Add bedspaces to a room in bulk: "Bed {n}" with count 6 → Bed 1 … Bed 6
     * (numbering continues after existing ones when start_number is omitted).
     *
     * @return Collection<int, RentableUnit>
     */
    public function addBedspaces(Room $room, int $count, string $pattern, ?int $startNumber, ?int $rentCentavos, User $by): Collection
    {
        if ($room->rental_mode !== RentalMode::Bedspaces) {
            throw ValidationException::withMessages(['count' => 'Switch the room to bedspace mode to add bedspaces.']);
        }
        $existing = $room->units()->count();
        if ($existing + $count > self::MAX_BEDSPACES) {
            throw ValidationException::withMessages(['count' => 'A room can have up to '.self::MAX_BEDSPACES.' bedspaces.']);
        }

        $start = $startNumber ?? $existing + 1;
        $order = (int) $room->units()->max('sort_order');

        return DB::transaction(function () use ($room, $count, $pattern, $start, $order, $rentCentavos, $by) {
            $created = collect();
            for ($i = 0; $i < $count; $i++) {
                $unit = $room->property->units()->create([
                    'room_id' => $room->id,
                    'kind' => UnitKind::Bedspace,
                    'label' => self::label($pattern, $start + $i),
                    'sort_order' => $order + $i + 1,
                    'capacity' => 1,
                ]);
                if ($rentCentavos !== null) {
                    $this->prices->set($unit, $rentCentavos, null, $by);
                }
                $created->push($unit);
            }

            return $created;
        });
    }

    /**
     * Everything still missing before a listing can be published.
     *
     * @return list<array{key: string, label: string, ok: bool}>
     */
    public function publishChecklist(Property $property): array
    {
        // Reuse what the caller already loaded (the property detail view).
        $units = $property->relationLoaded('units') ? $property->units : $property->units()->get();
        $this->prices->preload($units); // no-op for units the caller already preloaded
        $unpriced = $units->filter(fn (RentableUnit $u) => $this->prices->amountOn($u) === null)->count();
        $hasPhoto = $property->relationLoaded('photos') ? $property->photos->isNotEmpty() : $property->photos()->exists();

        return [
            ['key' => 'owner_verified', 'label' => 'Your owner account is verified', 'ok' => $property->owner->isVerifiedOwner()],
            ['key' => 'location', 'label' => 'Location pinned on the map', 'ok' => $property->latitude !== null && $property->longitude !== null],
            ['key' => 'photo', 'label' => 'At least one photo', 'ok' => $hasPhoto],
            ['key' => 'rent', 'label' => 'Rent set for every unit', 'ok' => $units->isNotEmpty() && $unpriced === 0],
            ['key' => 'ready_unit', 'label' => 'At least one unit ready to rent', 'ok' => $units->contains(fn ($u) => ! $u->not_ready)],
        ];
    }

    public static function label(string $pattern, int $n): string
    {
        $pattern = trim($pattern) !== '' ? $pattern : 'Bed {n}';

        return str_contains($pattern, '{n}') ? str_replace('{n}', (string) $n, $pattern) : "{$pattern} {$n}";
    }

    private function createUnitsFor(Room $room, RentalMode $mode, array $units, User $by): void
    {
        if ($mode === RentalMode::Whole) {
            $unit = $room->property->units()->create([
                'room_id' => $room->id,
                'kind' => UnitKind::Whole,
                'label' => 'Whole room',
                'sort_order' => 1,
                'capacity' => max(1, (int) ($units['capacity'] ?? 1)),
            ]);
            if (isset($units['rent_centavos'])) {
                $this->prices->set($unit, (int) $units['rent_centavos'], null, $by);
            }

            return;
        }

        $this->addBedspaces(
            $room,
            max(1, (int) ($units['count'] ?? 1)),
            (string) ($units['label_pattern'] ?? 'Bed {n}'),
            isset($units['start_number']) ? (int) $units['start_number'] : null,
            isset($units['rent_centavos']) ? (int) $units['rent_centavos'] : null,
            $by,
        );
    }
}
