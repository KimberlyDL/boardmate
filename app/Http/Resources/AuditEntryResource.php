<?php

namespace App\Http\Resources;

use App\Enums\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * One audit log line: who did what, in which role, when, with before → after.
 * Platform admins appear as "BoardMate team" to owners.
 *
 * @mixin Activity
 */
class AuditEntryResource extends JsonResource
{
    private const FIELD_LABELS = [
        'verification_status' => 'Status',
        'access_level' => 'Access level',
        'business_name' => 'Business name',
        'gcash_name' => 'GCash name',
        'gcash_number' => 'GCash number',
        'bank_name' => 'Bank',
        'bank_account_name' => 'Bank account name',
        'bank_account_number' => 'Bank account number',
        'email' => 'Email',
    ];

    private const ROLE_LABELS = [
        'owner' => 'Owner',
        'caretaker_manager' => 'Caretaker (Manager)',
        'caretaker_collector' => 'Caretaker (Collector)',
        'admin' => 'BoardMate admin',
        'self' => null,
        'system' => 'System',
    ];

    public function __construct($resource, private readonly bool $viewerIsAdmin = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $properties = $this->properties ?? collect();
        $hideAdmin = $this->acting_as === 'admin' && ! $this->viewerIsAdmin;

        return [
            'id' => $this->id,
            'event' => $this->event,
            'description' => AuditEvent::tryFrom((string) $this->event)?->label() ?? $this->description,
            'actor' => [
                'id' => $hideAdmin ? null : $this->causer_id,
                'name' => $hideAdmin ? 'BoardMate team' : ($properties->get('actor_name') ?? 'System'),
                'acting_as' => $this->acting_as,
                'acting_as_label' => self::ROLE_LABELS[$this->acting_as] ?? null,
            ],
            'note' => $properties->get('note'),
            'reason' => $properties->get('reason'),
            'changes' => collect($properties->get('changes', []))->map(fn (array $c, string $field) => [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? str($field)->replace('_', ' ')->ucfirst()->toString(),
                'old' => $c['old'] ?? null,
                'new' => $c['new'] ?? null,
            ])->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
