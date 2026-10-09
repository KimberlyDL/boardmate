<?php

namespace App\Http\Resources\Tenancies;

use App\Models\TenancyDiscount;
use App\Support\ManilaDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a tenancy's discount history. `value` is centavos for a fixed
 * discount and basis points (1000 = 10%) for a percent one.
 *
 * @mixin TenancyDiscount
 */
class TenancyDiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = ManilaDate::today();
        $status = match (true) {
            $this->effective_from->gt($today) => 'scheduled',
            $this->effective_to !== null && $this->effective_to->lt($today) => 'ended',
            default => 'active',
        };

        return [
            'id' => $this->id,
            'label' => TenancyDiscount::LABEL,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'value' => $this->value,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $status,
            'set_by' => $this->relationLoaded('setter') ? $this->setter?->name : null,
        ];
    }
}
