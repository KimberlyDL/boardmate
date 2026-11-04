<?php

namespace App\Services\Reports\Money;

use App\Enums\PropertyAbility;
use App\Models\Room;
use App\Services\Finance\FinanceFigures;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Each room's money for the dates picked, as on a property's finance screen. */
class RoomFinanceReport extends Report
{
    public function __construct(private readonly FinanceFigures $figures) {}

    public function key(): string
    {
        return 'room_finance';
    }

    public function label(): string
    {
        return 'Money by room';
    }

    public function description(): string
    {
        return 'Each room\'s billed and collected amounts, how much of what was due is paid, and unpaid balances and deposits held today.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function headers(): array
    {
        return ['Property', 'Room', 'Floor', 'Places', 'Taken', 'Billed (PHP)', 'Collected (PHP)', 'Paid from deposit (PHP)', 'Due in period (PHP)',
            'Paid of due (PHP)', 'Collected of due (%)', 'Unpaid today (PHP)', 'Overdue today (PHP)', 'Deposits held (PHP)', 'Repair costs (PHP)'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        foreach ($properties as $property) {
            $byRoom = $this->figures->grouped([$property->id], $from, $to, FinanceFigures::BY_ROOM);

            foreach (Room::where('property_id', $property->id)->ordered()->get() as $room) {
                yield $this->row($property->name, $room->label(), $room->floor, $byRoom[$room->id] ?? $this->figures->none(FinanceFigures::BY_ROOM));
            }
            if (isset($byRoom[0])) {
                yield $this->row($property->name, 'Rooms since removed', null, $byRoom[0]);
            }
        }
    }

    /** @return list<string|int|null> */
    private function row(string $property, string $room, ?int $floor, array $f): array
    {
        return [
            $property,
            $room,
            $floor,
            $f['occupancy']['places'],
            $f['occupancy']['occupied'],
            $this->pesos($f['billed']['total']),
            $this->pesos($f['collected']['total']),
            $this->pesos($f['paid_from_deposit']),
            $this->pesos($f['collection']['due']),
            $this->pesos($f['collection']['paid']),
            $f['collection']['rate'] === null ? '' : number_format($f['collection']['rate'] * 100, 1, '.', ''),
            $this->pesos($f['outstanding']['total']),
            $this->pesos($f['outstanding']['overdue']),
            $this->pesos($f['deposits']['held']),
            $this->pesos($f['repair_costs']),
        ];
    }
}
