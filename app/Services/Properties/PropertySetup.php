<?php

namespace App\Services\Properties;

use App\Enums\RentalMode;
use App\Enums\UnitKind;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Models\RentableUnit;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating and reshaping a property (Billing guide S1):
 * - whole mode keeps exactly one whole unit; bedspace mode has 1..N bedspaces;
 * - the rental mode switches only when no unit is in use (reserved, occupied…);
 * - every new property starts with the guide's default settings.
 */
class PropertySetup
{
    public const MAX_BEDSPACES = 200;

    public function __construct(private readonly PriceBook $prices) {}

    /**
     * @param  array<string, mixed>  $details  fillable property fields
     * @param  array{capacity?: int, rent_centavos?: int|null, count?: int, label_pattern?: string, start_number?: int}  $units
     */
    public function create(User $owner, array $details, RentalMode $mode, array $units, string $type): Property
    {
        return DB::transaction(function () use ($owner, $details, $mode, $units, $type) {
            $property = new Property($details);
            $property->forceFill([
                'owner_id' => $owner->id,
                'type' => $type,
                'rental_mode' => $mode,
            ])->save();

            $property->settings()->save(new PropertySettings);
            $this->createUnitsFor($property, $mode, $units, $owner);

            return $property;
        });
    }

    /**
     * Switch whole ⇄ bedspaces. Old units are archived (kept with their price
     * history), new ones created.
     *
     * @param  array{capacity?: int, rent_centavos?: int|null, count?: int, label_pattern?: string, start_number?: int}  $units
     */
    public function switchMode(Property $property, RentalMode $mode, array $units, User $by): void
    {
        if ($property->rental_mode === $mode) {
            throw ValidationException::withMessages(['mode' => 'The property already uses this rental mode.']);
        }
        if ($property->hasUnitsInUse()) {
            throw ValidationException::withMessages([
                'mode' => 'Someone is booked into or living in a unit. Switch the rental mode once all units are free.',
            ]);
        }

        DB::transaction(function () use ($property, $mode, $units, $by) {
            $property->units()->get()->each->delete();
            $property->forceFill(['rental_mode' => $mode])->save();
            $this->createUnitsFor($property, $mode, $units, $by);
        });
    }

    /**
     * Add bedspaces in bulk: "Bed {n}" with count 6 → Bed 1 … Bed 6 (numbering
     * continues after existing ones when start_number is omitted).
     *
     * @return Collection<int, RentableUnit>
     */
    public function addBedspaces(Property $property, int $count, string $pattern, ?int $startNumber, ?int $rentCentavos, User $by): Collection
    {
        if ($property->rental_mode !== RentalMode::Bedspaces) {
            throw ValidationException::withMessages(['count' => 'Switch the property to bedspace mode to add bedspaces.']);
        }
        $existing = $property->units()->count();
        if ($existing + $count > self::MAX_BEDSPACES) {
            throw ValidationException::withMessages(['count' => 'A property can have up to '.self::MAX_BEDSPACES.' bedspaces.']);
        }

        $start = $startNumber ?? $existing + 1;
        $order = (int) $property->units()->max('sort_order');

        return DB::transaction(function () use ($property, $count, $pattern, $start, $order, $rentCentavos, $by) {
            $created = collect();
            for ($i = 0; $i < $count; $i++) {
                $unit = $property->units()->create([
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
        $units = $property->units()->get();
        $unpriced = $units->filter(fn (RentableUnit $u) => $this->prices->amountOn($u) === null)->count();

        return [
            ['key' => 'owner_verified', 'label' => 'Your owner account is verified', 'ok' => $property->owner->isVerifiedOwner()],
            ['key' => 'location', 'label' => 'Location pinned on the map', 'ok' => $property->latitude !== null && $property->longitude !== null],
            ['key' => 'photo', 'label' => 'At least one photo', 'ok' => $property->photos()->exists()],
            ['key' => 'rent', 'label' => 'Rent set for every unit', 'ok' => $units->isNotEmpty() && $unpriced === 0],
            ['key' => 'ready_unit', 'label' => 'At least one unit ready to rent', 'ok' => $units->contains(fn ($u) => ! $u->not_ready)],
        ];
    }

    public static function label(string $pattern, int $n): string
    {
        $pattern = trim($pattern) !== '' ? $pattern : 'Bed {n}';

        return str_contains($pattern, '{n}') ? str_replace('{n}', (string) $n, $pattern) : "{$pattern} {$n}";
    }

    private function createUnitsFor(Property $property, RentalMode $mode, array $units, User $by): void
    {
        if ($mode === RentalMode::Whole) {
            $unit = $property->units()->create([
                'kind' => UnitKind::Whole,
                'label' => 'Whole property',
                'sort_order' => 1,
                'capacity' => max(1, (int) ($units['capacity'] ?? 1)),
            ]);
            if (isset($units['rent_centavos'])) {
                $this->prices->set($unit, (int) $units['rent_centavos'], null, $by);
            }

            return;
        }

        $this->addBedspaces(
            $property,
            max(1, (int) ($units['count'] ?? 1)),
            (string) ($units['label_pattern'] ?? 'Bed {n}'),
            isset($units['start_number']) ? (int) $units['start_number'] : null,
            isset($units['rent_centavos']) ? (int) $units['rent_centavos'] : null,
            $by,
        );
    }
}
