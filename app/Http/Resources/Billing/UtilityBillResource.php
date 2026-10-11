<?php

namespace App\Http\Resources\Billing;

use App\Enums\FilePurpose;
use App\Models\UtilityBill;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A metered bill as staff see it: the provider's total, its coverage, and how
 * it was split (each room's occupant-days and amount, or waived).
 *
 * @mixin UtilityBill
 */
class UtilityBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account' => ['id' => $this->utility_account_id, 'name' => $this->account?->name, 'type' => $this->account?->type->value],
            'coverage_from_on' => $this->coverage_from_on->toDateString(),
            'coverage_to_on' => $this->coverage_to_on->toDateString(),
            'total_centavos' => $this->total_centavos,
            'shares' => $this->shares ?? [],
            'photo_url' => $this->photo_path ? app(FileService::class)->url($this->photo_path, FilePurpose::UtilityBillPhoto) : null,
            'replaces_id' => $this->replaces_id,
            'correction_reason' => $this->correction_reason,
            'entered_by' => $this->enteredBy?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
