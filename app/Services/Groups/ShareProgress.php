<?php

namespace App\Services\Groups;

use App\Enums\RepaymentStatus;
use App\Models\ContributionGroup;
use Illuminate\Support\Facades\DB;

/**
 * How much of each share is already paid to the member it is owed to, so a
 * line can say "₱X of ₱Y collected". Paybacks are made per pair (not per
 * line), so for each member and the one they owe, what they paid is spread
 * over their shares oldest first (by due date, else date, then line).
 * Credits are the confirmed paybacks plus what the other owes them back and
 * any corrections that lower what they owe: the same totals as the balances.
 */
class ShareProgress
{
    /**
     * @return array<int, array<int, int>> line id => member id => centavos paid of their share
     */
    public function paid(ContributionGroup $group): array
    {
        $shares = DB::table('expense_shares as s')
            ->join('group_expenses as e', 'e.id', '=', 's.expense_id')
            ->where('e.group_id', $group->id)
            ->whereColumn('s.member_id', '!=', 'e.paid_by_member_id')
            ->orderByRaw('coalesce(e.due_on, e.spent_on), e.id')
            ->get(['e.id as line', 's.member_id as debtor', 'e.paid_by_member_id as creditor', 's.amount_centavos as amount']);

        $credit = []; // "debtor:creditor" => centavos available
        $add = function (int $from, int $to, int $amount) use (&$credit) {
            $credit["{$from}:{$to}"] = ($credit["{$from}:{$to}"] ?? 0) + $amount;
        };
        foreach ($shares as $s) {
            if ($s->amount < 0) {
                $add((int) $s->debtor, (int) $s->creditor, -(int) $s->amount); // a correction lowering what they owe
            } else {
                $add((int) $s->creditor, (int) $s->debtor, (int) $s->amount); // owed the other way: offsets
            }
        }
        $group->repayments()->reorder()->where('status', RepaymentStatus::Confirmed->value)
            ->get(['from_member_id', 'to_member_id', 'amount_centavos'])
            ->each(fn ($r) => $add((int) $r->from_member_id, (int) $r->to_member_id, (int) $r->amount_centavos));

        $paid = [];
        foreach ($shares as $s) {
            if ($s->amount <= 0) {
                continue;
            }
            $key = "{$s->debtor}:{$s->creditor}";
            $use = min((int) $s->amount, $credit[$key] ?? 0);
            $credit[$key] = ($credit[$key] ?? 0) - $use;
            $paid[(int) $s->line][(int) $s->debtor] = $use;
        }

        return $paid;
    }
}
