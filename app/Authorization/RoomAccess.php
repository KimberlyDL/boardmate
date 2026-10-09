<?php

namespace App\Authorization;

use App\Enums\PropertyAbility as A;
use App\Models\Room;
use App\Models\User;

/**
 * Who may see or change a room's leader and occupants (System Design C2).
 *
 * - Staff (the owner, and caretakers assigned to the property) go through
 *   PropertyAccess, so levels and owner separation still apply.
 * - The room's current leader may see the room's people and add them, but
 *   never sees their emergency contacts.
 */
final class RoomAccess
{
    public static function staffCan(User $user, Room $room, A $ability): bool
    {
        return PropertyAccess::can($user, $ability, $room->property);
    }

    public static function isLeader(User $user, Room $room): bool
    {
        return ! $user->isSuspended()
            && $room->leaders()->whereNull('ended_on')->where('user_id', $user->id)->exists();
    }

    /** Staff who can see the property, or the room's leader. */
    public static function canView(User $user, Room $room): bool
    {
        return self::staffCan($user, $room, A::View) || self::isLeader($user, $room);
    }

    /** Staff who manage tenancies, or the room's leader. */
    public static function canManageOccupants(User $user, Room $room): bool
    {
        return self::staffCan($user, $room, A::ManageTenancies) || self::isLeader($user, $room);
    }
}
