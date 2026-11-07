<?php

namespace App\Services\Billing;

use App\Enums\UtilityType;
use App\Models\UtilityAccount;
use Illuminate\Support\Collection;

/**
 * Which utility accounts reach which rooms: the property's accounts are the
 * default, and a room's own account for the same utility replaces them for
 * that room (owner decision 2026-11-07). So a room with its own meter is left
 * out of the property meter's split, and a room with its own fixed price pays
 * that price instead of the property's.
 *
 * "The same utility" is the same type; for "other" it is the same name, so
 * a room's LPG does not replace the property's cable TV.
 */
class UtilityCoverage
{
    /**
     * The accounts in force for a room: its own, plus the property's for
     * every utility the room has no account of its own for.
     *
     * @return Collection<int, UtilityAccount>
     */
    public function forRoom(int $propertyId, ?int $roomId): Collection
    {
        $accounts = UtilityAccount::query()
            ->where('property_id', $propertyId)
            ->where(fn ($q) => $q->whereNull('room_id')->when($roomId, fn ($q) => $q->orWhere('room_id', $roomId)))
            ->orderBy('id')
            ->get();

        $roomOwn = $accounts->whereNotNull('room_id')->map(fn (UtilityAccount $a) => self::utilityKey($a))->unique();

        return $accounts
            ->reject(fn (UtilityAccount $a) => $a->room_id === null && $roomOwn->contains(self::utilityKey($a)))
            ->values();
    }

    /**
     * The rooms an account covers: a room's account covers that room; the
     * property's covers every room without its own account for that utility.
     *
     * @return list<int>
     */
    public function roomIdsFor(UtilityAccount $account): array
    {
        if ($account->room_id !== null) {
            return [$account->room_id];
        }

        $key = self::utilityKey($account);
        $withOwn = UtilityAccount::query()
            ->where('property_id', $account->property_id)
            ->whereNotNull('room_id')
            ->get()
            ->filter(fn (UtilityAccount $a) => self::utilityKey($a) === $key)
            ->pluck('room_id')
            ->unique()
            ->all();

        return $account->property->rooms()->whereNotIn('id', $withOwn)->pluck('id')->all();
    }

    /** What makes two accounts "the same utility". */
    public static function utilityKey(UtilityAccount $account): string
    {
        return $account->type === UtilityType::Other
            ? 'other:'.mb_strtolower(trim($account->name))
            : $account->type->value;
    }
}
