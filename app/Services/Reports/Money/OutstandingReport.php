<?php

namespace App\Services\Reports\Money;

use App\Enums\PropertyAbility;
use App\Models\Bill;
use App\Services\Reports\Report;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Every bill with a balance today, with how long it is overdue (ageing). */
class OutstandingReport extends Report
{
    public function key(): string
    {
        return 'outstanding';
    }

    public function label(): string
    {
        return 'Outstanding balances';
    }

    public function description(): string
    {
        return 'Every unpaid bill as of today, grouped by how long it is overdue.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewArrears;
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function headers(): array
    {
        return ['Property', 'Payer', 'Room', 'Bill no.', 'Kind', 'Issued', 'Due', 'Days overdue', 'Ageing', 'Balance (PHP)'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $today = ManilaDate::today();

        $bills = Bill::whereIn('property_id', $properties->pluck('id'))->withBalance()->unpaid()
            ->with(['payer:id,name', 'room'])->orderBy('property_id')->orderBy('due_on')->orderBy('id')->get();

        foreach ($bills as $b) {
            $days = $b->due_on->lt($today) ? (int) $b->due_on->diffInDays($today) : 0;
            yield [
                $names[$b->property_id] ?? '',
                $b->payer?->name,
                $b->room?->label(),
                $b->number,
                $b->kind->label(),
                $this->day($b->issued_on),
                $this->day($b->due_on),
                $days,
                $this->bucket($days),
                $this->pesos($b->balanceCentavos()),
            ];
        }
    }

    private function bucket(int $days): string
    {
        return match (true) {
            $days === 0 => 'Not yet due',
            $days <= 30 => '1–30 days',
            $days <= 60 => '31–60 days',
            $days <= 90 => '61–90 days',
            default => 'Over 90 days',
        };
    }
}
