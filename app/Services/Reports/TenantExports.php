<?php

namespace App\Services\Reports;

use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\User;
use App\Services\Dashboard\TenantSpending;
use Carbon\CarbonImmutable;

/**
 * A boarder's own data as files (CSV, Excel or PDF through TableFile): their
 * spending month by month (the "My spending" page), every bill, and every
 * payment. Only their own rows, across every place they stayed.
 */
class TenantExports
{
    /** @var array<string, array{label: string, description: string}> */
    public const EXPORTS = [
        'spending' => ['label' => 'My spending by month', 'description' => 'The last 12 months: rent, utilities, other charges, what you paid and your room group shares.'],
        'bills' => ['label' => 'My bills', 'description' => 'Every bill: when it was issued and due, the total, what you paid and what is left.'],
        'payments' => ['label' => 'My payments', 'description' => 'Every payment the owner confirmed, with the receipt number.'],
    ];

    public function __construct(private readonly TenantSpending $spending) {}

    /** @return list<string> */
    public function headers(string $export): array
    {
        return match ($export) {
            'spending' => ['Month', 'Rent (PHP)', 'Utilities (PHP)', 'Other charges (PHP)', 'Billed (PHP)', 'Paid (PHP)', 'Room group shares (PHP)'],
            'bills' => ['Bill no.', 'Place', 'Kind', 'Issued', 'Due', 'Total (PHP)', 'Paid (PHP)', 'Balance (PHP)', 'Status'],
            'payments' => ['Date paid', 'Receipt no.', 'Place', 'Method', 'Reference', 'Amount (PHP)'],
        };
    }

    /** @return iterable<list<string|int|float|null>> */
    public function rows(string $export, User $user): iterable
    {
        return match ($export) {
            'spending' => $this->spendingRows($user),
            'bills' => $this->billRows($user),
            'payments' => $this->paymentRows($user),
        };
    }

    /** @return iterable<list<string|null>> */
    private function spendingRows(User $user): iterable
    {
        foreach ($this->spending->for($user)['months'] as $m) {
            yield [
                CarbonImmutable::parse($m['month'].'-01')->format('F Y'),
                self::pesos($m['rent']),
                self::pesos($m['utilities']),
                self::pesos($m['other']),
                self::pesos($m['rent'] + $m['utilities'] + $m['other']),
                self::pesos($m['paid']),
                self::pesos($m['group']),
            ];
        }
    }

    /** @return iterable<list<string|null>> */
    private function billRows(User $user): iterable
    {
        $bills = Bill::where('payer_id', $user->id)->withBalance()->with(['lines', 'property' => fn ($q) => $q->withTrashed()])
            ->lazyById(200, 'bills.id', 'id');
        foreach ($bills as $bill) {
            $total = $bill->totalCentavos();
            $balance = $bill->balanceCentavos();
            yield [
                $bill->number,
                $bill->property?->name,
                $bill->kind->label(),
                $bill->issued_on?->toDateString(),
                $bill->due_on?->toDateString(),
                self::pesos($total),
                self::pesos(max(0, $total - $balance)),
                self::pesos($balance),
                $balance <= 0 ? 'Paid' : ($bill->due_on?->lt(now('Asia/Manila')->startOfDay()) ? 'Overdue' : 'To pay'),
            ];
        }
    }

    /** @return iterable<list<string|null>> */
    private function paymentRows(User $user): iterable
    {
        $payments = Payment::where('payer_id', $user->id)->where('status', PaymentStatus::Confirmed->value)
            ->with(['property' => fn ($q) => $q->withTrashed()])->lazyById(200);
        foreach ($payments as $p) {
            yield [
                $p->paid_on?->toDateString(),
                $p->receipt_number,
                $p->property?->name,
                $p->method->label(),
                $p->reference,
                self::pesos($p->amount_centavos),
            ];
        }
    }

    private static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
