<?php

namespace App\Services\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TenancyPaymentKind;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Support\ManilaDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One stay's money from move-in to today, by the same rules as
 * FinanceFigures: billed is every line of the stay's bills plus the first
 * rent; paid is what payments applied to those bills (the deposit applied at
 * settlement shown apart) plus the first rent; the balance and overdue are as
 * of today.
 */
class StayFinance
{
    /**
     * Totals of each stay.
     *
     * @param  list<int>  $tenancyIds
     * @return array<int, array<string, int>>
     */
    public function summaries(array $tenancyIds): array
    {
        if ($tenancyIds === []) {
            return [];
        }

        $billed = $this->pluck(DB::table('bill_lines')->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
            ->whereIn('bills.tenancy_id', $tenancyIds), 'bills.tenancy_id', 'bill_lines.amount_centavos');
        $applied = fn (bool $fromDeposit) => $this->pluck(DB::table('payment_allocations as pa')
            ->join('payments', 'payments.id', '=', 'pa.payment_id')
            ->join('bill_lines', 'bill_lines.id', '=', 'pa.bill_line_id')
            ->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
            ->whereIn('bills.tenancy_id', $tenancyIds)
            ->where('payments.status', PaymentStatus::Confirmed->value)
            ->where('payments.method', $fromDeposit ? '=' : '!=', PaymentMethod::Deposit->value), 'bills.tenancy_id', 'pa.amount_centavos');
        $paid = $applied(false);
        $fromDeposit = $applied(true);

        $moveIn = TenancyPayment::whereIn('tenancy_id', $tenancyIds)
            ->selectRaw('tenancy_id, kind, sum(amount_centavos) as c')->groupBy('tenancy_id', 'kind')->get()
            ->groupBy('tenancy_id')->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->kind->value => (int) $r->c]));
        $settled = DB::table('settlements')->whereIn('tenancy_id', $tenancyIds)->pluck('tenancy_id')->flip();

        $today = ManilaDate::today();
        $owed = [];
        foreach (Bill::whereIn('tenancy_id', $tenancyIds)->withBalance()->unpaid()->get() as $bill) {
            $owed[$bill->tenancy_id] ??= ['balance' => 0, 'overdue' => 0];
            $owed[$bill->tenancy_id]['balance'] += $bill->balanceCentavos();
            if (! $bill->isUnderReview() && $bill->due_on->lt($today)) {
                $owed[$bill->tenancy_id]['overdue'] += $bill->balanceCentavos();
            }
        }

        return collect($tenancyIds)->mapWithKeys(function (int $id) use ($billed, $paid, $fromDeposit, $moveIn, $settled, $owed) {
            $firstRent = $moveIn[$id][TenancyPaymentKind::FirstRent->value] ?? 0;
            $deposit = $moveIn[$id][TenancyPaymentKind::Deposit->value] ?? 0;

            return [$id => [
                'billed' => ($billed[$id] ?? 0) + $firstRent,
                'paid' => ($paid[$id] ?? 0) + $firstRent,
                'paid_from_deposit' => $fromDeposit[$id] ?? 0,
                'balance' => $owed[$id]['balance'] ?? 0,
                'overdue' => $owed[$id]['overdue'] ?? 0,
                'deposit_received' => $deposit,
                'deposit_held' => isset($settled[$id]) ? 0 : $deposit,
            ]];
        })->all();
    }

    /**
     * Everything about one stay's money: move-in payments, each bill, the
     * payments applied to its bills, the totals and how the deposit was settled.
     *
     * @return array<string, mixed>
     */
    public function detail(Tenancy $tenancy): array
    {
        $tenancy->loadMissing(['tenant:id,name', 'room', 'unit', 'property:id,name', 'payments', 'settlement']);
        $today = ManilaDate::today();

        $bills = Bill::where('tenancy_id', $tenancy->id)->withBalance()->with('lines')
            ->orderBy('issued_on')->orderBy('id')->get()
            ->map(fn (Bill $b) => [
                'id' => $b->id,
                'number' => $b->number,
                'kind' => $b->kind->value,
                'kind_label' => $b->kind->label(),
                'period_start_on' => $b->period_start_on?->toDateString(),
                'period_end_on' => $b->period_end_on?->toDateString(),
                'issued_on' => $b->issued_on->toDateString(),
                'due_on' => $b->due_on->toDateString(),
                'total_centavos' => $b->totalCentavos(),
                'balance_centavos' => $b->balanceCentavos(),
                'overdue' => $b->balanceCentavos() > 0 && ! $b->isUnderReview() && $b->due_on->lt($today),
                'under_review' => $b->isUnderReview(),
                'late' => $b->isLate(),
            ]);

        $payments = Payment::query()
            ->join('payment_allocations as pa', 'pa.payment_id', '=', 'payments.id')
            ->join('bill_lines', 'bill_lines.id', '=', 'pa.bill_line_id')
            ->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
            ->where('bills.tenancy_id', $tenancy->id)
            ->where('payments.status', PaymentStatus::Confirmed->value)
            ->groupBy('payments.id')
            ->orderBy('payments.paid_on')->orderBy('payments.id')
            ->get(['payments.*', DB::raw('sum(pa.amount_centavos) as applied_centavos')])
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'receipt_number' => $p->receipt_number,
                'paid_on' => $p->paid_on->toDateString(),
                'method' => $p->method->value,
                'method_label' => $p->method->label(),
                'amount_centavos' => $p->amount_centavos,
                'applied_centavos' => (int) $p->applied_centavos,
            ]);

        $s = $tenancy->settlement;

        return [
            'stay' => [
                'id' => $tenancy->id,
                'status' => $tenancy->status->value,
                'tenant' => ['id' => $tenancy->tenant_id, 'name' => $tenancy->tenant?->name],
                'property' => ['id' => $tenancy->property_id, 'name' => $tenancy->property?->name],
                'room' => $tenancy->room ? ['id' => $tenancy->room->id, 'label' => $tenancy->room->label()] : null,
                'unit' => $tenancy->unit ? ['id' => $tenancy->unit->id, 'label' => $tenancy->unit->label] : null,
                'moved_in_on' => $tenancy->moved_in_on?->toDateString(),
                'moved_out_on' => $tenancy->moved_out_on?->toDateString(),
            ],
            'totals' => $this->summaries([$tenancy->id])[$tenancy->id],
            'move_in' => $tenancy->payments->sortBy('received_on')->values()->map(fn (TenancyPayment $p) => [
                'kind' => $p->kind->value,
                'kind_label' => $p->kind->label(),
                'amount_centavos' => $p->amount_centavos,
                'method' => $p->method->value,
                'method_label' => $p->method->label(),
                'received_on' => $p->received_on?->toDateString(),
                'reference' => $p->reference,
            ]),
            'bills' => $bills,
            'payments' => $payments,
            'settlement' => $s ? [
                'id' => $s->id,
                'deposit_held_centavos' => $s->deposit_held_centavos,
                'deductions_centavos' => $s->deductions_centavos,
                'applied_centavos' => $s->applied_centavos,
                'refund_centavos' => $s->refund_centavos,
                'kept_centavos' => $s->kept_centavos,
                'kept_reason' => $s->kept_reason,
                'still_owed_centavos' => $s->still_owed_centavos,
                'refunded_on' => $s->refunded_on?->toDateString(),
                'settled_on' => $s->created_at?->setTimezone('Asia/Manila')->toDateString(),
            ] : null,
        ];
    }

    /**
     * Stays of a room that overlap the period, newest first.
     *
     * @return Collection<int, Tenancy>
     */
    public function inRoom(int $roomId, string $from, string $to): Collection
    {
        return Tenancy::where('room_id', $roomId)
            ->where('moved_in_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('moved_out_on')->orWhere('moved_out_on', '>=', $from))
            ->with(['tenant:id,name', 'unit'])
            ->orderByDesc('moved_in_on')->orderByDesc('id')->get();
    }

    /** @return array<int, int> */
    private function pluck($query, string $key, string $amount): array
    {
        return $query->selectRaw("{$key} as k, sum({$amount}) as c")->groupBy($key)->pluck('c', 'k')->map(fn ($c) => (int) $c)->all();
    }
}
