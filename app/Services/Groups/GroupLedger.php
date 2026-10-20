<?php

namespace App\Services\Groups;

use App\Enums\RepaymentStatus;
use App\Models\GroupExpense;

/**
 * Bookkeeping every kind of group line shares (D5): writing a line's shares,
 * and whether the member who paid it has been paid back since it was
 * posted (after that, a line is corrected with a separate line, never edited).
 */
class GroupLedger
{
    /** @param  array<int, int>  $shares  member id => signed centavos */
    public function writeShares(GroupExpense $line, array $shares): void
    {
        $line->shares()->delete();
        foreach ($shares as $memberId => $amount) {
            if ($amount !== 0) {
                $line->shares()->create(['member_id' => $memberId, 'amount_centavos' => $amount]);
            }
        }
    }

    /** A repayment to the one who paid this line, confirmed after it was posted. */
    public function repaidSince(GroupExpense $line): bool
    {
        return $line->group->repayments()
            ->where('to_member_id', $line->paid_by_member_id)
            ->where('status', RepaymentStatus::Confirmed->value)
            ->where('created_at', '>=', $line->created_at)
            ->exists();
    }
}
