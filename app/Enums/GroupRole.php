<?php

namespace App\Enums;

/** A member's role in a room's shared-contributions group (D5). */
enum GroupRole: string
{
    /** The room leader, automatically. */
    case Admin = 'admin';
    /** Appointed by the leader or the owner: adds and edits expenses, confirms repayments. */
    case Moderator = 'moderator';
    /** Adds expenses, marks repayments, sees their own balance. */
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Leader',
            self::Moderator => 'Moderator',
            self::Member => 'Member',
        };
    }

    /** Can edit expenses and splits, and confirm repayments for others. */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }
}
