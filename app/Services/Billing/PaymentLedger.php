<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Models\BillLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies a payer's money to what they owe at one property (C6).
 *
 * Money is confirmed payments and credit lines (negative adjustments). It
 * settles charge lines oldest due date first; on the same due date rent, then
 * utilities, then other charges. A credit line settles its own bill first.
 * Money left over is credit, applied when the next bill is issued.
 *
 * `settle()` only adds allocations for money not yet applied, so it is safe to
 * call after anything that changes what is owed or paid.
 */
class PaymentLedger
{
    /** Apply unused money to open charges. Call after the change is saved. */
    public function settle(int $propertyId, int $payerId): void
    {
        DB::transaction(function () use ($propertyId, $payerId) {
            // One settle per payer at a time: two at once would apply the same money twice.
            User::whereKey($payerId)->lockForUpdate()->first();

            /** @var array<int, int> $open line id => centavos still owed */
            $charges = $this->openCharges($propertyId, $payerId);
            $open = $charges->mapWithKeys(fn (BillLine $l) => [$l->id => $l->amount_centavos - (int) $l->allocated])->filter()->all();
            if (! $open) {
                return;
            }

            foreach ($this->unusedCredits($propertyId, $payerId) as $credit) {
                $left = -$credit->amount_centavos - (int) $credit->used;
                $ownBillFirst = $charges->sortBy(fn (BillLine $l) => $l->bill_id === $credit->bill_id ? 0 : 1)->values();
                $this->apply($ownBillFirst, $open, $left, ['credit_line_id' => $credit->id]);
            }

            foreach ($this->unusedPayments($propertyId, $payerId) as $payment) {
                $left = $payment->amount_centavos - (int) $payment->allocated;
                $this->apply($charges, $open, $left, ['payment_id' => $payment->id]);
            }
        });
    }

    /**
     * What the payer owes and holds as credit at the property.
     *
     * @return array{owed_centavos: int, credit_centavos: int}
     */
    public function summary(int $propertyId, int $payerId): array
    {
        $owed = $this->openCharges($propertyId, $payerId)->sum(fn (BillLine $l) => $l->amount_centavos - (int) $l->allocated);
        $credit = $this->unusedPayments($propertyId, $payerId)->sum(fn (Payment $p) => $p->amount_centavos - (int) $p->allocated)
            + $this->unusedCredits($propertyId, $payerId)->sum(fn (BillLine $l) => -$l->amount_centavos - (int) $l->used);

        return ['owed_centavos' => (int) $owed, 'credit_centavos' => (int) $credit];
    }

    /**
     * @param  Collection<int, BillLine>  $charges  in the order to settle
     * @param  array<int, int>  $open
     */
    private function apply(Collection $charges, array &$open, int $left, array $source): void
    {
        foreach ($charges as $line) {
            if ($left <= 0) {
                return;
            }
            $owed = $open[$line->id] ?? 0;
            if ($owed <= 0) {
                continue;
            }
            $amount = min($owed, $left);
            PaymentAllocation::create($source + ['bill_line_id' => $line->id, 'amount_centavos' => $amount]);
            $open[$line->id] = $owed - $amount;
            $left -= $amount;
        }
    }

    /** @return Collection<int, BillLine> charge lines not fully settled, in settling order */
    private function openCharges(int $propertyId, int $payerId): Collection
    {
        return BillLine::query()
            ->whereHas('bill', fn ($q) => $q->where('property_id', $propertyId)->where('payer_id', $payerId))
            ->where('amount_centavos', '>', 0)
            ->withSum('allocations as allocated', 'amount_centavos')
            ->with('bill:id,due_on')
            ->get()
            ->filter(fn (BillLine $l) => $l->amount_centavos > (int) $l->allocated)
            ->sortBy([
                fn (BillLine $a, BillLine $b) => $a->bill->due_on <=> $b->bill->due_on,
                fn (BillLine $a, BillLine $b) => $a->kind->settleOrder() <=> $b->kind->settleOrder(),
                fn (BillLine $a, BillLine $b) => [$a->bill_id, $a->sort_order, $a->id] <=> [$b->bill_id, $b->sort_order, $b->id],
            ])
            ->values();
    }

    /** @return Collection<int, BillLine> credit lines with some amount not yet applied, oldest first */
    private function unusedCredits(int $propertyId, int $payerId): Collection
    {
        return BillLine::query()
            ->whereHas('bill', fn ($q) => $q->where('property_id', $propertyId)->where('payer_id', $payerId))
            ->where('amount_centavos', '<', 0)
            ->withSum('creditAllocations as used', 'amount_centavos')
            ->orderBy('id')
            ->get()
            ->filter(fn (BillLine $l) => -$l->amount_centavos > (int) $l->used)
            ->values();
    }

    /** @return Collection<int, Payment> confirmed payments with some amount not yet applied, oldest first */
    private function unusedPayments(int $propertyId, int $payerId): Collection
    {
        return Payment::query()
            ->where('property_id', $propertyId)
            ->where('payer_id', $payerId)
            ->where('status', PaymentStatus::Confirmed->value)
            ->withSum('allocations as allocated', 'amount_centavos')
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (Payment $p) => $p->amount_centavos > (int) $p->allocated)
            ->values();
    }
}
