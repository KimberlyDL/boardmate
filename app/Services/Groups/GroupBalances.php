<?php

namespace App\Services\Groups;

use App\Enums\RepaymentStatus;
use App\Models\ContributionGroup;
use App\Models\GroupMember;
use Illuminate\Support\Facades\DB;

/**
 * Who owes whom in a room group (D5). Each share of a line is owed to the
 * member who paid the line (the one billed, for owner bills). Within a pair
 * the two directions cancel out; there is no netting across three or more
 * people (settled one by one). Only confirmed repayments count.
 */
class GroupBalances
{
    /**
     * Every pair with something owed, largest first.
     *
     * @return list<array{from: int, to: int, amount_centavos: int}>
     */
    public function pairs(ContributionGroup $group): array
    {
        $owed = []; // "from:to" => centavos, from owes to

        DB::table('expense_shares as s')
            ->join('group_expenses as e', 'e.id', '=', 's.expense_id')
            ->where('e.group_id', $group->id)
            ->whereColumn('s.member_id', '!=', 'e.paid_by_member_id')
            ->selectRaw('s.member_id as debtor, e.paid_by_member_id as creditor, sum(s.amount_centavos) as total')
            ->groupBy('s.member_id', 'e.paid_by_member_id')
            ->get()
            ->each(function ($row) use (&$owed) {
                $this->add($owed, (int) $row->debtor, (int) $row->creditor, (int) $row->total);
            });

        $group->repayments()->reorder()->where('status', RepaymentStatus::Confirmed->value)
            ->selectRaw('from_member_id, to_member_id, sum(amount_centavos) as total')
            ->groupBy('from_member_id', 'to_member_id')
            ->get()
            ->each(function ($row) use (&$owed) {
                $this->add($owed, (int) $row->from_member_id, (int) $row->to_member_id, -(int) $row->total);
            });

        $pairs = [];
        foreach ($owed as $key => $amount) {
            [$a, $b] = array_map('intval', explode(':', $key));
            if ($amount > 0) {
                $pairs[] = ['from' => $a, 'to' => $b, 'amount_centavos' => $amount];
            } elseif ($amount < 0) {
                $pairs[] = ['from' => $b, 'to' => $a, 'amount_centavos' => -$amount];
            }
        }
        usort($pairs, fn ($x, $y) => $y['amount_centavos'] <=> $x['amount_centavos']);

        return $pairs;
    }

    /** What `$from` owes `$to` now (0 if nothing, or if it runs the other way). */
    public function owed(ContributionGroup $group, GroupMember $from, GroupMember $to): int
    {
        foreach ($this->pairs($group) as $pair) {
            if ($pair['from'] === $from->id && $pair['to'] === $to->id) {
                return $pair['amount_centavos'];
            }
        }

        return 0;
    }

    /** One running total per pair, keyed by the lower member id first. */
    private function add(array &$owed, int $debtor, int $creditor, int $amount): void
    {
        [$key, $sign] = $debtor < $creditor ? ["{$debtor}:{$creditor}", 1] : ["{$creditor}:{$debtor}", -1];
        $owed[$key] = ($owed[$key] ?? 0) + $sign * $amount;
    }
}
