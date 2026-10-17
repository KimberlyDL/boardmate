<?php

namespace App\Services\Documents;

use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Billing\StatementBuilder;
use App\Services\Documents\Contracts\DocumentService;
use App\Support\ManilaDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/** Renders the Blade views in resources/views/documents with dompdf (DejaVu Sans, which has the ₱ sign). */
class DompdfDocumentService implements DocumentService
{
    public function __construct(
        private readonly StatementBuilder $statements,
        private readonly PaymentLedger $ledger,
    ) {}

    public function receipt(Payment $payment): Response
    {
        abort_unless($payment->receipt_number && in_array($payment->status, [PaymentStatus::Confirmed, PaymentStatus::Reversed], true), 404);
        $payment->load(['property.owner.ownerProfile', 'payer', 'decider', 'reversal', 'allocations.line.bill']);

        return $this->render('documents.receipt', "{$payment->receipt_number}.pdf", [
            'payment' => $payment,
            'issuer' => $this->issuer($payment->property),
            'applied' => $payment->allocations->groupBy(fn ($a) => $a->line->bill->number)
                ->map(fn ($rows, $number) => ['bill' => $number, 'amount_centavos' => (int) $rows->sum('amount_centavos')])->values(),
            'credit_centavos' => $payment->status === PaymentStatus::Confirmed ? $payment->unallocatedCentavos() : 0,
        ]);
    }

    public function statement(Property $property, User $payer, ?string $from, ?string $to): Response
    {
        $property->loadMissing('owner.ownerProfile');
        $statement = $this->statements->build($property->id, $payer->id, $from, $to);

        return $this->render('documents.statement', 'statement-'.str($payer->name)->slug()."-{$statement['to']}.pdf", [
            'statement' => $statement,
            'payer' => $payer,
            'property' => $property,
            'issuer' => $this->issuer($property),
            'standing' => $this->ledger->summary($property->id, $payer->id),
        ]);
    }

    public function lateNotice(Bill $bill): Response
    {
        abort_unless($bill->isLate(), 404);
        $bill->load(['property.owner.ownerProfile', 'payer', 'room']);

        return $this->render('documents.late-notice', "{$bill->late_notice_number}.pdf", [
            'bill' => $bill,
            'issuer' => $this->issuer($bill->property),
            'balance_centavos' => $bill->balanceCentavos(),
            'days_overdue' => (int) $bill->due_on->diffInDays(ManilaDate::parse($bill->late_at)),
        ]);
    }

    public function settlementSummary(Settlement $settlement): Response
    {
        $settlement->load(['tenancy.property.owner.ownerProfile', 'tenancy.tenant', 'tenancy.room', 'tenancy.unit', 'settler']);
        $tenancy = $settlement->tenancy;

        return $this->render('documents.settlement', 'settlement-'.str($tenancy->tenant->name)->slug().'.pdf', [
            'settlement' => $settlement,
            'tenancy' => $tenancy,
            'issuer' => $this->issuer($tenancy->property),
        ]);
    }

    public function link(string $type, array $params): string
    {
        return URL::temporarySignedRoute("api.v1.documents.{$type}", now()->addMinutes(config('dormlink.private_file_url_minutes')), $params);
    }

    /** Who issues the document: the owner's business (or their name), and the property. */
    private function issuer(Property $property): array
    {
        $owner = $property->owner;

        return [
            'name' => $owner?->ownerProfile?->business_name ?: $owner?->name,
            'property' => $property->name,
            'address' => $property->fullAddress(),
            'phone' => $owner?->phone,
        ];
    }

    private function render(string $view, string $filename, array $data): Response
    {
        // Subsetting embeds only the letters used: about 30 KB instead of 900 KB a file.
        return Pdf::loadView($view, $data)->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->stream($filename);
    }
}
