<?php

namespace App\Services\Documents;

use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Billing\StatementBuilder;
use App\Services\Documents\Contracts\DocumentService;
use App\Services\Finance\FinanceChart;
use App\Services\Finance\FinanceOverview;
use App\Support\ManilaDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/** Renders the Blade views in resources/views/documents with dompdf (DejaVu Sans, which has the ₱ sign). */
class DompdfDocumentService implements DocumentService
{
    public function __construct(
        private readonly StatementBuilder $statements,
        private readonly PaymentLedger $ledger,
        private readonly FinanceOverview $finance,
        private readonly FinanceChart $charts,
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

    public function financeStatement(Collection $properties, string $from, string $to): Response
    {
        [$start, $end] = [ManilaDate::parse($from)->startOfDay(), ManilaDate::parse($to)->startOfDay()];
        $properties->loadMissing('owner.ownerProfile');
        $one = $properties->count() === 1 ? $properties->first() : null;

        if ($one) {
            $page = $this->finance->property($one, $start, $end);
            $rows = collect($page['rooms'])->map(fn ($r) => ['name' => $r['room'] ? 'Room '.$r['room']['label'] : 'Rooms since removed'] + $r);
        } else {
            $page = $this->finance->portfolio($properties, $start, $end);
            $rows = collect($page['by_property'])->map(fn ($r) => ['name' => $r['property']['name']] + $r);
        }
        $owner = $properties->first()?->owner;

        return $this->render('documents.finance', 'financial-statement-'.($one ? str($one->name)->slug() : 'all-properties')."-{$from}_to_{$to}.pdf", [
            'title' => $one ? $one->name : 'All properties',
            'page' => $page,
            'rows' => $rows,
            'rowsLabel' => $one ? 'By room' : 'By property',
            'issuer' => $one ? $this->issuer($one) : [
                'name' => $owner?->ownerProfile?->business_name ?: $owner?->name,
                'property' => $properties->count().' properties',
                'address' => null,
                'phone' => $owner?->phone,
            ],
            'charts' => $this->financeCharts($page),
        ]);
    }

    public function roomFinanceStatement(Room $room, string $from, string $to): Response
    {
        $room->loadMissing('property.owner.ownerProfile');
        $page = $this->finance->room($room, ManilaDate::parse($from)->startOfDay(), ManilaDate::parse($to)->startOfDay());

        return $this->render('documents.finance', 'financial-statement-room-'.str($room->label())->slug()."-{$from}_to_{$to}.pdf", [
            'title' => "Room {$room->label()}",
            'page' => $page,
            'rows' => collect($page['stays'])->map(fn ($s) => ['name' => $s['stay']['tenant']['name'] ?? 'Stay #'.$s['stay']['id']] + $s),
            'rowsLabel' => 'By stay (since move-in, balance today)',
            'issuer' => $this->issuer($room->property),
            'charts' => $this->financeCharts($page),
        ]);
    }

    public function stayFinanceStatement(Tenancy $tenancy): Response
    {
        $tenancy->loadMissing('property.owner.ownerProfile');
        $page = $this->finance->stay($tenancy);

        return $this->render('documents.stay-finance', 'stay-statement-'.str($page['stay']['tenant']['name'] ?? (string) $tenancy->id)->slug().'.pdf', [
            'page' => $page,
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

    /** @return array<string, string> chart name => image data URI */
    private function financeCharts(array $page): array
    {
        $t = $page['totals'];
        $age = $t['outstanding']['ageing'];

        return [
            'trend' => $this->charts->trend($page['series']),
            'occupancy' => isset($page['occupancy_series']) && collect($page['occupancy_series'])->contains(fn ($m) => $m['places'] > 0)
                ? $this->charts->occupancy($page['occupancy_series'])
                : null,
            'billed' => $this->charts->bars([
                'Rent' => $t['billed']['rent'],
                'Fixed utilities' => $t['billed']['fixed_utilities'],
                'Utility shares' => $t['billed']['utility_shares'],
                'Charges' => $t['billed']['charges'],
            ]),
            'ageing' => $this->charts->bars([
                'Not yet due' => $age['not_due'],
                '1-30 days late' => $age['days_1_30'],
                '31-60 days late' => $age['days_31_60'],
                '61-90 days late' => $age['days_61_90'],
                'Over 90 days late' => $age['over_90'],
                'Under review' => $t['outstanding']['under_review'],
            ]),
        ];
    }

    private function render(string $view, string $filename, array $data): Response
    {
        // Subsetting embeds only the letters used: about 30 KB instead of 900 KB a file.
        return Pdf::loadView($view, $data)->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->stream($filename);
    }
}
