<?php

namespace App\Services\Groups;

use App\Enums\RepaymentStatus;
use App\Models\GroupMember;
use Illuminate\Validation\ValidationException;

/**
 * A former member hides an old room group from their own list ("archive").
 * Only their list changes; nothing is deleted, because the group's lines,
 * shares and paybacks are also the other members' and the owner's records.
 * Allowed once they left the room and are settled up: they owe nobody,
 * nobody owes them, and no payback of theirs waits for confirmation.
 */
class GroupArchive
{
    public function __construct(private readonly GroupBalances $balances) {}

    /** Why the member cannot hide the group yet, or null when they can. */
    public function blocker(GroupMember $member): ?string
    {
        if ($member->isCurrent()) {
            return 'You can hide a room group only after you leave the room.';
        }
        $open = collect($this->balances->pairs($member->group))
            ->filter(fn (array $p) => $p['from'] === $member->id || $p['to'] === $member->id);
        if ($open->isNotEmpty()) {
            return 'Settle up first: you still owe someone in this group, or someone owes you.';
        }
        $waiting = $member->group->repayments()->reorder()->where('status', RepaymentStatus::Pending->value)
            ->where(fn ($q) => $q->where('from_member_id', $member->id)->orWhere('to_member_id', $member->id))
            ->exists();
        if ($waiting) {
            return 'A payback with you is still waiting to be confirmed.';
        }

        return null;
    }

    public function hide(GroupMember $member): GroupMember
    {
        if ($reason = $this->blocker($member)) {
            throw ValidationException::withMessages(['group' => $reason]);
        }
        $member->forceFill(['hidden_at' => now()])->save();

        return $member;
    }

    public function unhide(GroupMember $member): GroupMember
    {
        $member->forceFill(['hidden_at' => null])->save();

        return $member;
    }
}
