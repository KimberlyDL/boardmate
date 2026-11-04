<?php

namespace App\Services\Dashboard;

use App\Enums\BillLineKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TenancyPaymentKind;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\ManilaDate;
use Illuminate\Support\Facades\DB;

/**
 * "My spending" (A11): what a boarder was billed and paid, month by month,
 * across every place they stayed, from the same rows as their bills.
 *
 * - Billed: rent (with the first rent paid at move-in), utilities (fixed and
 *   shares) and other charges (one-time charges and corrections), by the
 *   bill's issue date; corrections by the day they were added.
 * - Paid: confirmed payments by the day paid, without the deposit used at
 *   settlement, plus the first rent.
 * - Room group: my shares of the room's costs, by the day spent, except the
 *   lines of bills billed to me (those are already in my bills).
 * - Deposits: paid at move-in, still held, refunded and kept.
 */
class TenantSpending
{
    private const UTILITIES = [BillLineKind::FixedUtility, BillLineKind::UtilityShare];

    /** @return array<string, mixed> */
    public function for(User $user, int $months = 12): array
    {
        $to = ManilaDate::today()->endOfMonth()->startOfDay();
        $from = $to->startOfMonth()->subMonths($months - 1);
        $period = [$from->toDateString(), $to->toDateString()];
        $stayIds = Tenancy::where('tenant_id', $user->id)->pluck('id');

        $billed = $this->billed($user, $period);
        $paid = $this->sumByMonth(DB::table('payments')->where('payer_id', $user->id)
            ->where('status', PaymentStatus::Confirmed->value)->where('method', '!=', PaymentMethod::Deposit->value)
            ->whereBetween('paid_on', $period), 'paid_on', 'amount_centavos');
        $firstRent = $this->sumByMonth(DB::table('tenancy_payments')->whereIn('tenancy_id', $stayIds)
            ->where('kind', TenancyPaymentKind::FirstRent->value)->whereBetween('received_on', $period), 'received_on', 'amount_centavos');
        $group = $this->groupShares($user, $period);

        $series = collect(range(0, $months - 1))->map(function (int $i) use ($from, $billed, $paid, $firstRent, $group) {
            $m = $from->addMonths($i)->format('Y-m');
            $rent = ($billed[$m]['rent'] ?? 0) + ($firstRent[$m] ?? 0);

            return [
                'month' => $m,
                'rent' => $rent,
                'utilities' => $billed[$m]['utilities'] ?? 0,
                'other' => $billed[$m]['other'] ?? 0,
                'paid' => ($paid[$m] ?? 0) + ($firstRent[$m] ?? 0),
                'group' => $group[$m] ?? 0,
            ];
        });

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'months' => $series->all(),
            'totals' => [
                'rent' => $series->sum('rent'),
                'utilities' => $series->sum('utilities'),
                'other' => $series->sum('other'),
                'billed' => $series->sum(fn ($m) => $m['rent'] + $m['utilities'] + $m['other']),
                'paid' => $series->sum('paid'),
                'group' => $series->sum('group'),
            ],
            'deposits' => $this->deposits($stayIds->all()),
        ];
    }

    /** @return array<string, array{rent: int, utilities: int, other: int}> month => figures */
    private function billed(User $user, array $period): array
    {
        $lines = fn () => DB::table('bill_lines')->join('bills', 'bills.id', '=', 'bill_lines.bill_id')->where('bills.payer_id', $user->id);
        $rows = $lines()->where('bill_lines.kind', '!=', BillLineKind::Adjustment->value)->whereBetween('bills.issued_on', $period)
            ->selectRaw("to_char(bills.issued_on, 'YYYY-MM') as m, bill_lines.kind, sum(bill_lines.amount_centavos) as c")
            ->groupByRaw("to_char(bills.issued_on, 'YYYY-MM'), bill_lines.kind")->get()
            ->concat($lines()->where('bill_lines.kind', BillLineKind::Adjustment->value)->whereRaw('bill_lines.created_at::date between ? and ?', $period)
                ->selectRaw("to_char(bill_lines.created_at, 'YYYY-MM') as m, bill_lines.kind, sum(bill_lines.amount_centavos) as c")
                ->groupByRaw("to_char(bill_lines.created_at, 'YYYY-MM'), bill_lines.kind")->get());

        $out = [];
        foreach ($rows as $row) {
            $kind = BillLineKind::from($row->kind);
            $name = $kind === BillLineKind::Rent ? 'rent' : (in_array($kind, self::UTILITIES, true) ? 'utilities' : 'other');
            $out[$row->m][$name] = ($out[$row->m][$name] ?? 0) + (int) $row->c;
        }

        return $out;
    }

    /** @return array<string, int> month => my shares of the room groups' costs */
    private function groupShares(User $user, array $period): array
    {
        return DB::table('expense_shares as s')
            ->join('group_expenses as e', 'e.id', '=', 's.expense_id')
            ->join('group_members as gm', 'gm.id', '=', 's.member_id')
            ->leftJoin('tenancies as t', 't.id', '=', 'gm.tenancy_id')
            ->leftJoin('bills as b', 'b.id', '=', 'e.bill_id')
            ->where(fn ($q) => $q->where('gm.user_id', $user->id)->orWhere('t.tenant_id', $user->id))
            ->where(fn ($q) => $q->whereNull('b.payer_id')->orWhere('b.payer_id', '!=', $user->id))
            ->whereBetween('e.spent_on', $period)
            ->selectRaw("to_char(e.spent_on, 'YYYY-MM') as m, sum(s.amount_centavos) as c")
            ->groupByRaw("to_char(e.spent_on, 'YYYY-MM')")
            ->pluck('c', 'm')->map(fn ($c) => (int) $c)->all();
    }

    /** @return array<string, int> month => centavos */
    private function sumByMonth($query, string $dateColumn, string $amountColumn): array
    {
        return $query->selectRaw("to_char({$dateColumn}, 'YYYY-MM') as m, sum({$amountColumn}) as c")
            ->groupByRaw("to_char({$dateColumn}, 'YYYY-MM')")
            ->pluck('c', 'm')->map(fn ($c) => (int) $c)->all();
    }

    /**
     * @param  list<int>  $stayIds
     * @return array{paid: int, held: int, refunded: int, kept: int}
     */
    private function deposits(array $stayIds): array
    {
        $paid = DB::table('tenancy_payments')->whereIn('tenancy_id', $stayIds)->where('kind', TenancyPaymentKind::Deposit->value)
            ->selectRaw('tenancy_id, sum(amount_centavos) as c')->groupBy('tenancy_id')->pluck('c', 'tenancy_id');
        $settled = DB::table('settlements')->whereIn('tenancy_id', $stayIds)
            ->get(['tenancy_id', 'refund_centavos', 'kept_centavos'])->keyBy('tenancy_id');

        return [
            'paid' => (int) $paid->sum(),
            'held' => (int) $paid->filter(fn ($c, $id) => ! $settled->has($id))->sum(),
            'refunded' => (int) $settled->sum('refund_centavos'),
            'kept' => (int) $settled->sum('kept_centavos'),
        ];
    }
}
