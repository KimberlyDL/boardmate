<?php

namespace App\Http\Resources\Properties;

use App\Models\UtilityAccount;
use App\Services\Pricing\PriceBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UtilityAccount */
class UtilityAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $prices = app(PriceBook::class);
        $fixed = $this->method->hasFixedAmount();
        $upcoming = $fixed ? $prices->upcoming($this->resource) : null;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'billed_by' => $this->billed_by->value,
            'billed_by_label' => $this->billed_by->label(),
            'notes' => $this->notes,
            'has_fixed_amount' => $fixed,
            'amount_centavos' => $fixed ? $prices->amountOn($this->resource) : null,
            'upcoming_amount' => $upcoming ? [
                'amount_centavos' => $upcoming->amount_centavos,
                'effective_from' => $upcoming->effective_from->toDateString(),
            ] : null,
        ];
    }
}
