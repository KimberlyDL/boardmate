<?php

namespace App\Http\Resources\Properties;

use App\Models\PriceRule;
use App\Support\ManilaDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceRule */
class PriceRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = ManilaDate::today();

        return [
            'id' => $this->id,
            'amount_centavos' => $this->amount_centavos,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'state' => match (true) {
                $this->effective_from->greaterThan($today) => 'scheduled',
                $this->effective_to !== null && $this->effective_to->lessThan($today) => 'past',
                default => 'current',
            },
            'set_by' => $this->setter?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
