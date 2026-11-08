<?php

namespace App\Services\Finance;

use App\Enums\LateEntryStatus;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceUrgency;
use App\Models\CurfewViolation;
use App\Models\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Insights screens' house figures (FR-87), next to the money: repairs and
 * late entries in a period, computed on each request; nothing is stored.
 *
 * - Repairs: reported (by the day reported), fixed (by the day marked
 *   resolved) with the average days it took, cancelled, by kind, and what is
 *   open now (whatever the period). Repair costs stay with the money figures.
 * - Late entries: requests to come back after curfew, by their night and
 *   status; curfew violations (late without approval) by night; and stays
 *   with CurfewViolation::REPEAT_COUNT or more violations in the period.
 *
 * For one room, repairs count where the room was set (common-area repairs
 * show only for the whole property) and late entries by the stay's room.
 */
class HouseFigures
{
    /** Stays listed under "late most often", at most. */
    private const REPEATED_LIMIT = 10;

    /**
     * @param  list<int>  $propertyIds
     * @return array{repairs: array<string, mixed>, late_entries: array<string, mixed>}
     */
    public function figures(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to, ?int $roomId = null): array
    {
        $period = [$from->toDateString(), $to->toDateString()];

        return [
            'repairs' => $this->repairs($propertyIds, $period, $roomId),
            'late_entries' => $this->lateEntries($propertyIds, $period, $roomId),
        ];
    }

    /**
     * The figures of the period before that are compared ("vs before").
     *
     * @param  list<int>  $propertyIds
     * @return array{repairs_reported: int, repairs_fixed: int, late_requests: int, curfew_violations: int}
     */
    public function previous(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to, ?int $roomId = null): array
    {
        $period = [$from->toDateString(), $to->toDateString()];

        return [
            'repairs_reported' => $this->repairQuery($propertyIds, $roomId)->whereRaw('created_at::date between ? and ?', $period)->count(),
            'repairs_fixed' => $this->repairQuery($propertyIds, $roomId)->whereRaw('resolved_at::date between ? and ?', $period)->count(),
            'late_requests' => $this->nightQuery('late_entry_requests', $propertyIds, $roomId)->whereBetween('late_entry_requests.on_date', $period)->count(),
            'curfew_violations' => $this->nightQuery('curfew_violations', $propertyIds, $roomId)->whereBetween('curfew_violations.on_date', $period)->count(),
        ];
    }

    /**
     * @param  list<int>  $propertyIds
     * @param  list<string>  $period
     * @return array<string, mixed>
     */
    private function repairs(array $propertyIds, array $period, ?int $roomId): array
    {
        $reported = $this->repairQuery($propertyIds, $roomId)->whereRaw('created_at::date between ? and ?', $period);
        $fixed = $this->repairQuery($propertyIds, $roomId)->whereRaw('resolved_at::date between ? and ?', $period);
        $open = $this->repairQuery($propertyIds, $roomId)
            ->whereIn('status', [MaintenanceStatus::Submitted->value, MaintenanceStatus::InProgress->value]);
        $averageDays = (clone $fixed)->selectRaw('avg(extract(epoch from (resolved_at - created_at)) / 86400) as days')->value('days');

        $byKind = (clone $reported)->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category');

        return [
            'reported' => (clone $reported)->count(),
            'fixed' => (clone $fixed)->count(),
            'cancelled' => $this->repairQuery($propertyIds, $roomId)->whereRaw('cancelled_at::date between ? and ?', $period)->count(),
            'average_days_to_fix' => $averageDays === null ? null : round((float) $averageDays, 1),
            'open_now' => (clone $open)->count(),
            'urgent_open_now' => (clone $open)->where('urgency', MaintenanceUrgency::Urgent->value)->count(),
            'by_kind' => collect(MaintenanceCategory::cases())
                ->map(fn (MaintenanceCategory $c) => ['kind' => $c->value, 'label' => $c->label(), 'count' => (int) ($byKind[$c->value] ?? 0)])
                ->filter(fn (array $k) => $k['count'] > 0)
                ->sortByDesc('count')->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $propertyIds
     * @param  list<string>  $period
     * @return array<string, mixed>
     */
    private function lateEntries(array $propertyIds, array $period, ?int $roomId): array
    {
        $requests = $this->nightQuery('late_entry_requests', $propertyIds, $roomId)
            ->whereBetween('late_entry_requests.on_date', $period)
            ->selectRaw('late_entry_requests.status, count(*) as n')->groupBy('late_entry_requests.status')
            ->pluck('n', 'status');

        $nights = $this->nightQuery('curfew_violations', $propertyIds, $roomId)
            ->whereBetween('curfew_violations.on_date', $period)
            ->selectRaw('curfew_violations.tenancy_id, count(*) as nights')->groupBy('curfew_violations.tenancy_id')
            ->pluck('nights', 'tenancy_id')->map(fn ($n) => (int) $n);
        $repeated = $nights->filter(fn (int $n) => $n >= CurfewViolation::REPEAT_COUNT)->sortDesc()->take(self::REPEATED_LIMIT);
        $stays = Tenancy::with(['tenant:id,name', 'room'])->whereIn('id', $repeated->keys())->get()->keyBy('id');

        return [
            'requests' => [
                'approved' => (int) ($requests[LateEntryStatus::Approved->value] ?? 0),
                'declined' => (int) ($requests[LateEntryStatus::Declined->value] ?? 0),
                'waiting' => (int) ($requests[LateEntryStatus::Pending->value] ?? 0),
                'cancelled' => (int) ($requests[LateEntryStatus::Cancelled->value] ?? 0),
            ],
            'curfew_violations' => $nights->sum(),
            'repeat_count' => CurfewViolation::REPEAT_COUNT,
            'repeated' => $repeated->map(fn (int $count, int $id) => [
                'stay' => [
                    'id' => $id,
                    'tenant' => $stays->get($id)?->tenant?->name,
                    'room' => $stays->get($id)?->room?->label(),
                ],
                'nights' => $count,
            ])->values()->all(),
        ];
    }

    /** @param  list<int>  $propertyIds */
    private function repairQuery(array $propertyIds, ?int $roomId): Builder
    {
        return DB::table('maintenance_requests')->whereIn('property_id', $propertyIds)
            ->when($roomId !== null, fn (Builder $q) => $q->where('room_id', $roomId));
    }

    /**
     * Late-entry requests or curfew violations of the properties, or of the
     * stays in one room.
     *
     * @param  list<int>  $propertyIds
     */
    private function nightQuery(string $table, array $propertyIds, ?int $roomId): Builder
    {
        return DB::table($table)->whereIn("{$table}.property_id", $propertyIds)
            ->when($roomId !== null, fn (Builder $q) => $q->join('tenancies', 'tenancies.id', '=', "{$table}.tenancy_id")
                ->where('tenancies.room_id', $roomId));
    }
}
