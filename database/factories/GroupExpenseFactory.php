<?php

namespace Database\Factories;

use App\Enums\GroupExpenseKind;
use App\Enums\SplitMethod;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass who paid with paidBy() (the group comes from them).
 *
 * @extends Factory<GroupExpense>
 */
class GroupExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => GroupExpenseKind::MemberExpense,
            'description' => 'Internet, October',
            'amount_centavos' => 150000,
            'spent_on' => '2026-10-05',
            'split_method' => SplitMethod::Equal,
        ];
    }

    public function paidBy(GroupMember $member): static
    {
        return $this->state(fn () => ['group_id' => $member->group_id, 'paid_by_member_id' => $member->id]);
    }
}
