<?php

namespace App\Services\Billing;

use App\Enums\BillLineKind;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Payment;
use App\Support\ManilaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A statement of account (A9): one payer's bills, corrections and payments at
 * one property, by date, with a running balance. Positive = owed; the closing
 * balance equals what the PaymentLedger shows (owed minus credit).
 *
 * - A bill counts on its issue date at its issued lines; a correction
 *   (adjustment line) counts on the day it was added.
 * - Confirmed payments count on the day paid. A reversed payment still shows
 *   on its day, and its reversal adds the amount back on the reversal day.
 * - Pending and rejected payments are left out.
 */
class StatementBuilder
{
    /**
     * @return array{from: string, to: string, opening_centavos: int, closing_centavos: int,
     *   charges_centavos: int, payments_centavos: int (payments and credits, as a positive amount),
     *   entries: list<array{date: string, kind: string, reference: ?string, description: string, amount_centavos: int, balance_centavos: int}>}
     */
    public function build(int $propertyId, int $payerId, ?string $from = null, ?string $to = null): array
    {
        $all = $this->entries($propertyId, $payerId);
        $to ??= ManilaDate::today()->toDateString();
        $from ??= $all->first()['date'] ?? $to;

        $opening = (int) $all->where('date', '<', $from)->sum('amount_centavos');
        $balance = $opening;
        $inRange = $all->filter(fn (array $e) => $e['date'] >= $from && $e['date'] <= $to)->values()
            ->map(function (array $e) use (&$balance) {
                $balance += $e['amount_centavos'];

                return $e + ['balance_centavos' => $balance];
            });

        return [
            'from' => $from,
            'to' => $to,
            'opening_centavos' => $opening,
            'closing_centavos' => $balance,
            'charges_centavos' => (int) $inRange->where('amount_centavos', '>', 0)->sum('amount_centavos'),
            'payments_centavos' => (int) -$inRange->where('amount_centavos', '<', 0)->sum('amount_centavos'),
            'entries' => $inRange->all(),
        ];
    }

    /** @return Collection<int, array{date: string, order: int, kind: string, reference: ?string, description: string, amount_centavos: int}> */
    private function entries(int $propertyId, int $payerId): Collection
    {
        $entries = collect();

        $bills = Bill::where('property_id', $propertyId)->where('payer_id', $payerId)->with('lines')->get();
        foreach ($bills as $bill) {
            [$corrections, $issued] = $bill->lines->partition(fn (BillLine $l) => $l->kind === BillLineKind::Adjustment);
            $entries->push([
                'date' => $bill->issued_on->toDateString(), 'order' => 1, 'kind' => 'bill', 'reference' => $bill->number,
                'description' => $bill->kind->label().($bill->period_start_on ? ', '.$bill->period_start_on->format('M j').' – '.$bill->period_end_on->format('M j, Y') : '').', due '.$bill->due_on->format('M j, Y'),
                'amount_centavos' => (int) $issued->sum('amount_centavos'),
            ]);
            foreach ($corrections as $line) {
                $entries->push([
                    'date' => ManilaDate::parse($line->created_at)->toDateString(), 'order' => 2, 'kind' => 'correction', 'reference' => $bill->number,
                    'description' => $line->description.($line->reason ? " ({$line->reason})" : ''),
                    'amount_centavos' => (int) $line->amount_centavos,
                ]);
            }
        }

        $payments = Payment::where('property_id', $propertyId)->where('payer_id', $payerId)
            ->whereIn('status', [PaymentStatus::Confirmed->value, PaymentStatus::Reversed->value])
            ->with('reverses')->get();
        foreach ($payments as $p) {
            $entries->push($p->reverses_id
                ? ['date' => $p->paid_on->toDateString(), 'order' => 4, 'kind' => 'reversal', 'reference' => $p->reverses?->receipt_number,
                    'description' => 'Payment reversed'.($p->note ? " ({$p->note})" : ''), 'amount_centavos' => $p->amount_centavos]
                : ['date' => $p->paid_on->toDateString(), 'order' => 3, 'kind' => 'payment', 'reference' => $p->receipt_number,
                    'description' => 'Payment, '.$p->method->label().($p->reference ? ", ref {$p->reference}" : ''), 'amount_centavos' => -$p->amount_centavos]);
        }

        return $entries->sortBy([['date', 'asc'], ['order', 'asc'], ['reference', 'asc']])->values();
    }

    /** "₱1,800.00" or "₱300.00 credit" for a balance. */
    public static function describe(int $centavos): string
    {
        return $centavos < 0 ? Money::format(-$centavos).' credit' : Money::format($centavos);
    }

    /** @param  CarbonImmutable|string  $date */
    public static function day($date): string
    {
        return CarbonImmutable::parse($date)->format('M j, Y');
    }
}
