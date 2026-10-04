<?php

namespace App\Services\Audit;

use BackedEnum;

/** Builds the before → after changes passed to AuditService::record(). */
final class AuditDiff
{
    /**
     * Only the fields whose value changed. Enums compare by value; '' equals null.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $new) {
            $old = $before[$field] ?? null;
            if (self::comparable($old) !== self::comparable($new)) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }

    private static function comparable(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return $value === '' ? null : $value;
    }
}
