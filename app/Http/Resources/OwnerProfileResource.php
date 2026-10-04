<?php

namespace App\Http\Resources;

use App\Enums\FilePurpose;
use App\Models\OwnerDocument;
use App\Models\OwnerProfile;
use App\Services\Files\Contracts\FileService;
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
            // Signed, expiring links; null once the file is deleted (90 days after the decision).
            'documents' => $this->documents->map(fn (OwnerDocument $d) => [
                'id' => $d->id,
                'kind' => $d->kind->value,
                'kind_label' => $d->kind->label(),
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => $d->size_bytes,
                'url' => app(FileService::class)->url($d->path, FilePurpose::OwnerDocument),
                'uploaded_at' => $d->created_at?->toIso8601String(),
                'purged_at' => $d->purged_at?->toIso8601String(),
            ])->values(),
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
