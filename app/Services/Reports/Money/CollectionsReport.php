<?php

namespace App\Services\Reports\Money;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PropertyAbility;
use App\Enums\TenancyPaymentKind;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Money received by the day it was paid (C6): confirmed payments and the
 * first rent paid at move-in. Reversed payments are left out, and so is the
 * deposit applied at settlement (it was received at move-in; see the
 * deposit ledger). The same money as "collected" on the finance screens.
 */
class CollectionsReport extends Report
{
    public function key(): string
    {
        return 'collections';
    }

    public function label(): string
    {
        return 'Collections';
    }

    public function description(): string
    {
        return 'Every confirmed payment and first rent at move-in between two dates, with its receipt number.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::RecordPayments;
    }

    public function headers(): array
    {
        return ['Date paid', 'Receipt no.', 'Property', 'Payer', 'Room', 'Method', 'Reference', 'Amount (PHP)', 'Recorded by'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $rooms = $this->roomsOfPayers($properties->pluck('id')->all());

        $payments = Payment::whereIn('property_id', $properties->pluck('id'))
            ->where('status', PaymentStatus::Confirmed)
            ->where('method', '!=', PaymentMethod::Deposit)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->with(['payer:id,name', 'submitter:id,name', 'decider:id,name'])
            ->orderBy('paid_on')->orderBy('id');
        $firstRents = TenancyPayment::where('kind', TenancyPaymentKind::FirstRent)
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->whereHas('tenancy', fn ($q) => $q->whereIn('property_id', $properties->pluck('id')))
            ->with(['tenancy.tenant:id,name', 'tenancy.room', 'receiver:id,name'])
            ->orderBy('received_on')->orderBy('id')->get();

        $next = 0;
        foreach ($payments->lazy(200) as $p) {
            while ($next < $firstRents->count() && $firstRents[$next]->received_on->lte($p->paid_on)) {
                yield $this->firstRentRow($firstRents[$next++], $names);
            }
            yield [
                $this->day($p->paid_on),
                $p->receipt_number,
                $names[$p->property_id] ?? '',
                $p->payer?->name,
                $rooms["{$p->property_id}:{$p->payer_id}"] ?? '',
                $p->method->label(),
                $p->reference,
                $this->pesos($p->amount_centavos),
                $p->decider?->name ?? $p->submitter?->name,
            ];
        }
        while ($next < $firstRents->count()) {
            yield $this->firstRentRow($firstRents[$next++], $names);
        }
    }

    /**
     * @param  Collection<int, string>  $names
     * @return list<string|null>
     */
    private function firstRentRow(TenancyPayment $t, Collection $names): array
    {
        return [
            $this->day($t->received_on),
            '',
            $names[$t->tenancy->property_id] ?? '',
            $t->tenancy->tenant?->name,
            $t->tenancy->room?->label() ?? '',
            "{$t->kind->label()} · {$t->method->label()}",
            $t->reference,
            $this->pesos($t->amount_centavos),
            $t->receiver?->name,
        ];
    }

    /**
     * The room of each payer's latest stay at each property.
     *
     * @param  list<int>  $propertyIds
     * @return array<string, string> "property:payer" => room label
     */
    private function roomsOfPayers(array $propertyIds): array
    {
        $rooms = [];
        Tenancy::whereIn('property_id', $propertyIds)->with('room')->orderBy('moved_in_on')->get()
            ->each(function (Tenancy $t) use (&$rooms) {
                $rooms["{$t->property_id}:{$t->tenant_id}"] = $t->room?->label() ?? '';
            });

        return $rooms;
    }
}
