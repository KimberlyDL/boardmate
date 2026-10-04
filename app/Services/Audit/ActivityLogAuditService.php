<?php

namespace App\Services\Audit;

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Stores entries in spatie/laravel-activitylog's `activity_log` table, plus
 * our owner_id / acting_as columns.
 */
class ActivityLogAuditService implements AuditService
{
    /** Shown as •••• 6789; full values never enter the log. */
    public const MASKED_FIELDS = ['bank_account_number', 'gcash_number'];

    /** Never stored at all. */
    public const SECRET_FIELDS = ['password', 'remember_token', 'token', 'token_hash', 'current_password'];

    public function record(
        AuditEvent $event,
        ?Model $subject = null,
        array $changes = [],
        ?User $owner = null,
        ?string $reason = null,
        ?User $actor = null,
        ?string $note = null,
    ): void {
        $actor ??= auth()->user();

        Activity::create([
            'log_name' => $event->scope(),
            'event' => $event->value,
            'description' => $event->label(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $actor?->getMorphClass(),
            'causer_id' => $actor?->getKey(),
            'owner_id' => $owner?->id,
            'acting_as' => $this->actingAs($event, $actor, $owner),
            'properties' => array_filter([
                'actor_name' => $actor?->name,
                'changes' => $this->clean($changes),
                'reason' => $reason,
                'note' => $note,
            ], fn ($v) => $v !== null && $v !== []),
        ]);
    }

    /** owner, caretaker_manager, caretaker_collector, admin, self or system. */
    private function actingAs(AuditEvent $event, ?User $actor, ?User $owner): string
    {
        if (! $actor) {
            return 'system';
        }
        if ($event->scope() === 'admin' && $actor->hasAccountRole(UserRole::PlatformAdmin)) {
            return 'admin';
        }
        if ($owner && $actor->is($owner)) {
            return 'owner';
        }
        if ($owner) {
            $link = $actor->employerLinks()->active()->where('owner_id', $owner->id)->first();
            if ($link) {
                return 'caretaker_'.$link->access_level->value;
            }
        }

        return 'self';
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    private function clean(array $changes): array
    {
        $out = [];
        foreach ($changes as $field => [$old, $new]) {
            if (in_array($field, self::SECRET_FIELDS, true)) {
                continue;
            }
            if (in_array($field, self::MASKED_FIELDS, true)) {
                [$old, $new] = [self::mask($old), self::mask($new)];
            }
            $out[$field] = ['old' => self::plain($old), 'new' => self::plain($new)];
        }

        return $out;
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : $value->value;
        }

        return $value === '' ? null : $value;
    }

    private static function mask(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value);

        return '•••• '.substr($digits !== '' ? $digits : (string) $value, -4);
    }
}
