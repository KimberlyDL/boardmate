<?php

namespace App\Services\Reports\Money;

use App\Enums\PaymentStatus;
use App\Enums\PropertyAbility;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Confirmed payments by the day they were paid (C6). Reversed ones are left out. */
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
        return 'Every confirmed payment between two dates, with its receipt number.';
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
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->with(['payer:id,name', 'submitter:id,name', 'decider:id,name'])
            ->orderBy('paid_on')->orderBy('id');

        foreach ($payments->lazy(200) as $p) {
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
