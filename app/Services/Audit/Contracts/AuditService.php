<?php

namespace App\Services\Audit\Contracts;

use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit module: the paper trail (who did what, when, in which role, before →
 * after). Entries are never edited or deleted.
 */
interface AuditService
{
    /**
     * @param  Model|null  $subject  the record acted on
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [before, after] (see AuditDiff); sensitive fields are masked
     * @param  User|null  $owner  whose business this belongs to (scopes the owner's log)
     * @param  User|null  $actor  defaults to the signed-in user; null for the system
     * @param  string|null  $note  short context shown with the entry (e.g. who was invited)
     */
    public function record(
        AuditEvent $event,
        ?Model $subject = null,
        array $changes = [],
        ?User $owner = null,
        ?string $reason = null,
        ?User $actor = null,
        ?string $note = null,
    ): void;
}
