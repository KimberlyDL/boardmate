<?php

namespace App\Http\Resources\Billing;

use App\Models\Bill;
use App\Models\BillLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bill with its lines and total. The caller loads `lines`, `payer` and `room`.
 *
 * @mixin Bill
 */
class BillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'property_id' => $this->property_id,
            'payer' => ['id' => $this->payer_id, 'name' => $this->payer?->name],
            'room_label' => $this->room?->label(),
            'tenancy_id' => $this->tenancy_id,
            'period_start_on' => $this->period_start_on?->toDateString(),
            'period_end_on' => $this->period_end_on?->toDateString(),
            'issued_on' => $this->issued_on->toDateString(),
            'due_on' => $this->due_on->toDateString(),
            'total_centavos' => $this->totalCentavos(),
            'lines' => $this->lines->map(fn (BillLine $l) => [
                'id' => $l->id,
                'kind' => $l->kind->value,
                'kind_label' => $l->kind->label(),
                'description' => $l->description,
                'amount_centavos' => $l->amount_centavos,
                'reason' => $l->reason,
                'adjusts_line_id' => $l->adjusts_line_id,
            ])->values(),
        ];
    }
}
