<?php

namespace App\Http\Resources\Properties;

use App\Enums\ApplicationStatus;
use App\Enums\UnitStatus;
use App\Models\BookingApplication;
use App\Models\RentableUnit;
use App\Services\Pricing\PriceBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RentableUnit */
class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $prices = app(PriceBook::class);
        $upcoming = $prices->upcoming($this->resource);

        return [
            'id' => $this->id,
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

        $application = BookingApplication::with('boarder:id,name')
            ->where('unit_id', $this->id)
            ->where('status', ApplicationStatus::Approved)
            ->first();

        return $application ? [
            'application_id' => $application->id,
            'boarder_name' => $application->boarder->name,
            'reserved_until' => $application->reserved_until->toDateString(),
        ] : null;
    }
}
