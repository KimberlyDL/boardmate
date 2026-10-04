<?php

namespace App\Http\Resources;

use App\Models\OwnerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The owner's own view (and the admin's): verification state, application
 * and payment instructions.
 *
 * @mixin OwnerProfile
 */
class OwnerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'business_name' => $this->business_name,
            'application_notes' => $this->application_notes,
            'verification_status' => $this->verification_status->value,
            'verification_label' => $this->verification_status->label(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_reason' => $this->review_reason,
            'payment' => [
                'gcash_name' => $this->gcash_name,
                'gcash_number' => $this->gcash_number,
                'bank_name' => $this->bank_name,
                'bank_account_name' => $this->bank_account_name,
                'bank_account_number' => $this->bank_account_number,
            ],
        ];
    }
}
