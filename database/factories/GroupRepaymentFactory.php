<?php

namespace Database\Factories;

use App\Enums\RepaymentMethod;
use App\Enums\RepaymentStatus;
use App\Models\GroupMember;
use App\Models\GroupRepayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass who paid whom with between().
 *
 * @extends Factory<GroupRepayment>
 */
class GroupRepaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'amount_centavos' => 50000,
            'method' => RepaymentMethod::Cash,
            'status' => RepaymentStatus::Pending,
        ];
    }

    public function between(GroupMember $from, GroupMember $to): static
    {
        return $this->state(fn () => ['group_id' => $from->group_id, 'from_member_id' => $from->id, 'to_member_id' => $to->id]);
    }
}
