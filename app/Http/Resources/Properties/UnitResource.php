<?php

namespace App\Http\Resources\Properties;

use App\Enums\UnitStatus;
use App\Models\RentableUnit;
use App\Services\Pricing\PriceBook;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin RentableUnit */
class UnitResource extends JsonResource
{
    /**
     * Load what every unit row shows (rents, reservation holder) for the
     * whole list at once. Call before rendering a list of units.
     *
     * @param  Collection<int, RentableUnit>  $units
     * @return Collection<int, RentableUnit>
     */
    public static function prepare(Collection $units): Collection
    {
        app(PriceBook::class)->preload($units);
        (new EloquentCollection($units->all()))->loadMissing('activeReservation.boarder:id,name');

        return $units;
    }

    public function toArray(Request $request): array
    {
        $prices = app(PriceBook::class);
        $upcoming = $prices->upcoming($this->resource);

        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'sort_order' => $this->sort_order,
            'capacity' => $this->capacity,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'not_ready' => $this->not_ready,
            'not_ready_reason' => $this->not_ready_reason,
            'rent_centavos' => $prices->amountOn($this->resource),
            // F2: who holds the reservation, for owners/caretakers.
            'reservation' => $this->reservation(),
            'upcoming_rent' => $upcoming ? [
                'amount_centavos' => $upcoming->amount_centavos,
                'effective_from' => $upcoming->effective_from->toDateString(),
            ] : null,
        ];
    }

    /** @return array{application_id: int, boarder_name: string, reserved_until: string}|null */
    private function reservation(): ?array
    {
        if ($this->status !== UnitStatus::Reserved) {
            return null;
        }

        $application = $this->activeReservation;

        return $application ? [
            'application_id' => $application->id,
            'boarder_name' => $application->boarder->name,
            'reserved_until' => $application->reserved_until->toDateString(),
        ] : null;
    }
}
