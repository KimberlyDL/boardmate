<?php

namespace App\Http\Resources\Maintenance;

use App\Enums\FilePurpose;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceNote;
use App\Models\MaintenancePhoto;
use App\Models\MaintenanceRequest;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A maintenance request (D1). `staff`: the owner or a caretaker (sees the
 * repair cost and who may do what); otherwise the reporter. Photos and the
 * history come with the detail view only.
 *
 * @mixin MaintenanceRequest
 */
class MaintenanceRequestResource extends JsonResource
{
    /** @param  array{edit?: bool, manage?: bool}  $can  what staff may do */
    public function __construct($resource, private readonly bool $staff = false, private readonly bool $detail = false, private readonly array $can = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $files = app(FileService::class);
        $open = $this->status->isOpen();

        return [
            'id' => $this->id,
            'property' => ['id' => $this->property_id, 'name' => $this->property?->name],
            'room' => $this->room ? ['id' => $this->room->id, 'label' => $this->room->label()] : null,
            'reported_by' => ['id' => $this->reported_by, 'name' => $this->reporter?->name],
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'urgency' => $this->urgency->value,
            'urgency_label' => $this->urgency->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'assignee' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null,
            'resolution' => $this->resolution,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'photos_count' => $this->whenCounted('photos'),
            'repair_cost_centavos' => $this->when($this->staff, $this->repair_cost_centavos),
            // The repair charged to the boarder, if any (they see it in their bills too).
            'charge' => $this->when($this->detail, fn () => $this->chargeBill ? [
                'bill_id' => $this->chargeBill->id,
                'number' => $this->chargeBill->number,
                'amount_centavos' => (int) $this->chargeBill->lines->sum('amount_centavos'),
            ] : null),
            // The unit Not ready can be set on: the one used before, else the stay's.
            'unit' => $this->when($this->detail && $this->staff, fn () => ($unit = $this->unit ?? $this->tenancy?->unit)
                ? ['id' => $unit->id, 'label' => $unit->label, 'not_ready' => (bool) $unit->not_ready, 'not_ready_reason' => $unit->not_ready_reason]
                : null),
            'photos' => $this->when($this->detail, fn () => $this->photos->map(fn (MaintenancePhoto $p) => $files->url($p->path, FilePurpose::MaintenancePhoto))->values()),
            'history' => $this->when($this->detail, fn () => $this->notes->map(fn (MaintenanceNote $n) => [
                'author' => $n->author?->name,
                'body' => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values()),
            'can' => $this->when($this->detail, fn () => [
                'start' => $this->staff && ($this->can['edit'] ?? false) && $this->status === MaintenanceStatus::Submitted,
                'resolve' => $this->staff && ($this->can['edit'] ?? false) && $open,
                'assign' => $this->staff && ($this->can['edit'] ?? false) && $open,
                'set_cost' => $this->staff && ($this->can['manage'] ?? false),
                'cancel' => $open && ($this->staff ? ($this->can['manage'] ?? false) : $this->status === MaintenanceStatus::Submitted),
                'confirm' => $this->reported_by === $request->user()?->id && $this->status === MaintenanceStatus::Resolved,
                'reopen' => $this->reported_by === $request->user()?->id && $this->status === MaintenanceStatus::Resolved,
                'charge' => $this->staff && ($this->can['manage'] ?? false) && $this->tenancy_id !== null && $this->charge_bill_id === null,
                'set_not_ready' => $this->staff && ($this->can['manage'] ?? false),
                'add_note' => ! in_array($this->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true),
            ]),
        ];
    }
}
