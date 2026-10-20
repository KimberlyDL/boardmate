<?php

namespace App\Services\Reports\Money;

use App\Enums\PropertyAbility;
use App\Models\PriceRule;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\UtilityAccount;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Every rent and utility price set, by the day it takes effect (F5): what, how much, from and to. */
class PriceHistoryReport extends Report
{
    public function key(): string
    {
        return 'prices';
    }

    public function label(): string
    {
        return 'Price change history';
    }

    public function description(): string
    {
        return 'Every rent and utility price that takes effect between two dates, and who set it.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function headers(): array
    {
        return ['Property', 'Applies to', 'Amount (PHP)', 'Effective from', 'Effective to', 'Set by', 'Recorded on'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $rules = PriceRule::whereIn('property_id', $properties->pluck('id'))
            ->whereBetween('effective_from', [$from->toDateString(), $to->toDateString()])
            ->with(['priceable' => fn ($q) => $q->withoutGlobalScopes(), 'setter:id,name'])
            ->orderBy('property_id')->orderBy('effective_from')->orderBy('id')->get();

        foreach ($rules as $r) {
            yield [
                $names[$r->property_id] ?? '',
                $this->appliesTo($r->priceable),
                $this->pesos((int) $r->amount_centavos),
                $this->day($r->effective_from),
                $this->day($r->effective_to),
                $r->setter?->name,
                $this->day($r->created_at?->setTimezone('Asia/Manila')),
            ];
        }
    }

    private function appliesTo(mixed $priceable): string
    {
        return match (true) {
            $priceable instanceof RentableUnit => trim('Rent · room '.($priceable->room?->label() ?? '').' · '.$priceable->label),
            $priceable instanceof UtilityAccount => "Utility · {$priceable->name}",
            $priceable instanceof Property => 'Whole property',
            default => '(removed)',
        };
    }
}
