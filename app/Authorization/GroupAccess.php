<?php

namespace App\Authorization;

use App\Enums\GroupRole;
use App\Enums\PropertyAbility as A;
use App\Models\ContributionGroup;
use App\Models\GroupMember;
use App\Models\User;

/**
 * Who may see or run a room's group (D5).
 *
 * - Members (current or former, with an account) see the whole group.
 * - The admin (room leader) and moderators manage it: splits, expenses,
 *   confirming repayments. Former members only read.
 * - The owner and caretakers see only the members and the owner's own bills
 *   posted to the group, never repayments or members' own expenses. The
 *   owner or a Manager may appoint moderators.
 */
final class GroupAccess
{
    public static function member(User $user, ContributionGroup $group): ?GroupMember
    {
        if ($user->isSuspended()) {
            return null;
        }

        return $group->members()->where('user_id', $user->id)->orderByRaw('left_on is not null')->first();
    }

    public static function canManage(User $user, ContributionGroup $group): bool
    {
        $member = self::member($user, $group);

        return $member !== null && $member->isCurrent() && $member->role->canManage();
    }

    public static function isStaff(User $user, ContributionGroup $group): bool
    {
        return PropertyAccess::can($user, A::View, $group->room->property);
    }

    /** The leader, or the owner or a Manager. */
    public static function canAppointModerators(User $user, ContributionGroup $group): bool
    {
        $member = self::member($user, $group);

        return ($member?->isCurrent() && $member->role === GroupRole::Admin)
            || PropertyAccess::can($user, A::ManageTenancies, $group->room->property);
    }
}
