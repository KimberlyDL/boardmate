<?php

namespace App\Http\Resources\Billing;

use App\Enums\FilePurpose;
use App\Models\Payment;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recorded payment. The caller loads `payer`, `submitter`, `decider` and,
 * for a reversed one, `reversal`. `$referenceReused` is only for staff.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function __construct(Payment $resource, private readonly ?bool $referenceReused = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'property_name' => $this->whenLoaded('property', fn () => $this->property?->name),
            'payer' => ['id' => $this->payer_id, 'name' => $this->payer?->name],
            'amount_centavos' => $this->amount_centavos,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'reference' => $this->reference,
            'proof_url' => app(FileService::class)->url($this->proof_path, FilePurpose::PaymentProof),
            'paid_on' => $this->paid_on->toDateString(),
            'note' => $this->note,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'receipt_number' => $this->receipt_number,
            'submitted_by' => $this->submitter?->name,
            'reported_by_payer' => $this->submitted_by === $this->payer_id,
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'reversal' => $this->whenLoaded('reversal', fn () => $this->reversal ? [
                'by' => $this->reversal->submitter?->name,
                'on' => $this->reversal->paid_on->toDateString(),
                'reason' => $this->reversal->note,
            ] : null),
            'reference_reused' => $this->when($this->referenceReused !== null, $this->referenceReused),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
