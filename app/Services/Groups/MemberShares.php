<?php

namespace App\Services\Groups;

use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Support\ManilaDate;

/**
 * What a room-group member has to pay in: what they owe in the group, and
 * their part still unpaid of each shared bill that is not paid yet (owner
 * bills and "collect first"), soonest due first. For everyone in a group,
 * and the only money view of someone the renter listed in a whole room
 * (they have no bills of their own: the owner bills the renter).
 */
class MemberShares
{
    public function __construct(
        private readonly GroupBalances $balances,
        private readonly ShareProgress $progress,
    ) {}

    /**
     * @return array{group_id: int, property_name: string, room_label: string, owe_centavos: int, owed_to_me_centavos: int,
     *     items: list<array{line_id: int, description: string, kind: string, due_on: string, my_left_centavos: int, is_overdue: bool, owed_to: string}>}
     */
    public function for(GroupMember $me): array
    {
        $group = $me->group->loadMissing('room.property');
        $pairs = collect($this->balances->pairs($group));

        return [
            'group_id' => $group->id,
            'property_name' => $group->room->property->name,
            'room_label' => $group->room->label(),
            'owe_centavos' => (int) $pairs->where('from', $me->id)->sum('amount_centavos'),
            'owed_to_me_centavos' => (int) $pairs->where('to', $me->id)->sum('amount_centavos'),
            'items' => $this->items($me),
        ];
    }

    /**
     * My unpaid part of each shared bill not paid yet, soonest due first.
     *
     * @return list<array{line_id: int, description: string, kind: string, due_on: string, my_left_centavos: int, is_overdue: bool, owed_to: string}>
     */
    public function items(GroupMember $me): array
    {
        $today = ManilaDate::today();
        $lines = GroupExpense::where('group_id', $me->group_id)
            ->where('paid_by_member_id', '!=', $me->id)
            ->whereHas('shares', fn ($q) => $q->where('member_id', $me->id)->where('amount_centavos', '>', 0))
            ->with(['paidBy:id,name', 'shares' => fn ($q) => $q->where('member_id', $me->id), 'bill' => fn ($q) => $q->withBalance()])
            ->get()
            ->filter(fn (GroupExpense $e) => $e->isCollecting() || ($e->bill && $e->bill->balanceCentavos() > 0));
        if ($lines->isEmpty()) {
            return [];
        }

        $paid = $this->progress->paid($me->group);

        return $lines->map(function (GroupExpense $e) use ($paid, $me, $today) {
            $due = $e->due_on ?? $e->bill?->due_on;
            $left = (int) $e->shares->first()->amount_centavos - ($paid[$e->id][$me->id] ?? 0);

            return $due && $left > 0 ? [
                'line_id' => $e->id,
                'description' => $e->description,
                'kind' => $e->kind->value,
                'due_on' => $due->toDateString(),
                'my_left_centavos' => $left,
                'is_overdue' => $due->lt($today),
                'owed_to' => $e->paidBy->name,
            ] : null;
        })->filter()->sortBy('due_on')->values()->all();
    }
}
