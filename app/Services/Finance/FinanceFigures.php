<?php

namespace App\Services\Finance;

use App\Enums\BillLineKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TenancyPaymentKind;
use App\Enums\UnitStatus;
use App\Models\Bill;
use App\Models\UtilityBill;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Money figures for the finance screens, computed on each request from the
 * same rows as bills, the payment ledger and the CSV reports; nothing is
 * stored.
 *
 * - Billed: issued lines on the bill's issue date (rent, fixed utilities,
 *   utility shares, one-time charges), corrections on the day they were
 *   added (signed), and the first rent paid at move-in on the day received.
 * - Collected: confirmed payments by the day paid, except the deposit applied
 *   at settlement (already received at move-in), plus the first rent. By
 *   room, a payment counts where it was applied; money not yet applied to a
 *   bill shows as `unapplied` on the property.
 * - Collection: of the bills due in the period, how much is paid so far.
 * - Outstanding, overdue and credit are as of today, whatever the period.
 *   Overdue leaves out bills under review, like the overdue list (C7).
 * - Deposits: received in the period, held now (stays not yet settled),
 *   refunded and kept at settlements in the period.
 * - Utilities and repair costs are for information; there is no profit figure.
 *
 * Every figure is grouped by property, by room (within one property), or by
 * month (for the trend).
 */
class FinanceFigures
{
    public const BY_PROPERTY = 'property';

    public const BY_ROOM = 'room';

    public const BY_MONTH = 'month';

    /** Room and month together ("12:2026-10"), for one room's trend. */
    private const BY_ROOM_MONTH = 'room_month';

    /** Bill line kind => figure name in `billed`. */
    private const BILLED_KINDS = [
        'rent' => 'rent',
        'fixed_utility' => 'fixed_utilities',
        'utility_share' => 'utility_shares',
        'one_time' => 'charges',
        'adjustment' => 'corrections',
    ];

    /**
     * The figures of each property, or of each room of one property (key 0 =
     * money whose room was deleted).
     *
     * @param  list<int>  $propertyIds
     * @return array<int, array<string, mixed>>
     */
    public function grouped(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to, string $by = self::BY_PROPERTY): array
    {
        $period = [$from->toDateString(), $to->toDateString()];
        $byProperty = $by === self::BY_PROPERTY;

        $parts = [
            'billed' => $this->billed($propertyIds, $period, $by),
            'payments' => $this->payments($propertyIds, $period, $by, PaymentStatus::Confirmed, false),
            'first_rent' => $this->moveInPayments($propertyIds, $period, $by, TenancyPaymentKind::FirstRent),
            'unapplied' => $byProperty ? $this->unapplied($propertyIds, $period) : [],
            'from_deposit' => $this->payments($propertyIds, $period, $by, PaymentStatus::Confirmed, true),
            'due' => $this->due($propertyIds, $period, $by),
            'paid_of_due' => $this->paidOfDue($propertyIds, $period, $by),
            'outstanding' => $this->outstanding($propertyIds, $by),
            'credit' => $byProperty ? $this->credit($propertyIds) : [],
            'deposits_received' => $this->moveInPayments($propertyIds, $period, $by, TenancyPaymentKind::Deposit),
            'deposits_held' => $this->depositsHeld($propertyIds, $by),
            'settlements' => $this->settlements($propertyIds, $period, $by),
            'provider' => $byProperty ? $this->providerCost($propertyIds, $period) : [],
            'repairs' => $this->repairCosts($propertyIds, $period, $by),
            'occupancy' => $this->occupancy($propertyIds, $by),
        ];

        $keys = collect($parts)->flatMap(fn (array $part) => array_keys($part))->unique()->values();
        if ($byProperty) {
            $keys = collect($propertyIds);
        }

        return $keys->mapWithKeys(fn ($key) => [(int) $key => $this->figures($parts, $key, $byProperty)])->all();
    }

    /**
     * Billed and collected per month, oldest first, for the months that end
     * with $to's month: of the properties, or of one room of them.
     *
     * @param  list<int>  $propertyIds
     * @return list<array{month: string, billed: int, collected: int}>
     */
    public function series(array $propertyIds, CarbonImmutable $to, int $months = 12, ?int $roomId = null): array
    {
        $from = $to->startOfMonth()->subMonths($months - 1);
        $period = [$from->toDateString(), $to->endOfMonth()->toDateString()];
        $by = $roomId === null ? self::BY_MONTH : self::BY_ROOM_MONTH;
        $billed = $this->billed($propertyIds, $period, $by);
        $payments = $this->payments($propertyIds, $period, $by, PaymentStatus::Confirmed, false);
        $firstRent = $this->moveInPayments($propertyIds, $period, $by, TenancyPaymentKind::FirstRent);

        return collect(range(0, $months - 1))->map(function (int $i) use ($from, $roomId, $billed, $payments, $firstRent) {
            $month = $from->addMonths($i)->format('Y-m');
            $k = $roomId === null ? $month : "{$roomId}:{$month}";

            return [
                'month' => $month,
                'billed' => array_sum($billed[$k] ?? []) + ($firstRent[$k] ?? 0),
                'collected' => ($payments[$k] ?? 0) + ($firstRent[$k] ?? 0),
            ];
        })->all();
    }

    /**
     * Places taken at the end of each month (today for this month), oldest
     * first, rebuilt from move-in and move-out dates: of the properties, or of
     * one room of them. Nothing is stored, so a month shows the places that
     * existed then (added before the month ended, or with a stay that month;
     * not yet removed). A stay in a room's whole-room option takes all of the
     * room's places. `rate` is null when there were no places.
     *
     * @param  list<int>  $propertyIds
     * @return list<array{month: string, places: int, occupied: int, rate: ?float}>
     */
    public function occupancySeries(array $propertyIds, CarbonImmutable $to, int $months = 12, ?int $roomId = null): array
    {
        $from = $to->startOfMonth()->subMonths($months - 1);
        $today = ManilaDate::today()->toDateString();

        $units = DB::table('rentable_units')->whereIn('property_id', $propertyIds)
            ->when($roomId !== null, fn ($q) => $q->where('room_id', $roomId))
            ->get(['id', 'room_id', 'is_whole_option', 'created_at', 'deleted_at']);
        $stays = DB::table('tenancies')->whereIn('unit_id', $units->pluck('id'))
            ->where('moved_in_on', '<=', min($today, $to->endOfMonth()->toDateString()))
            ->where(fn ($q) => $q->whereNull('moved_out_on')->orWhere('moved_out_on', '>', $from->toDateString()))
            ->get(['unit_id', 'moved_in_on', 'moved_out_on']);
        $byId = $units->keyBy('id');

        return collect(range(0, $months - 1))->map(function (int $i) use ($from, $today, $units, $stays, $byId) {
            $start = $from->addMonths($i);
            $end = min($today, $start->endOfMonth()->toDateString());
            $month = $start->format('Y-m');
            if ($end < $start->toDateString()) {
                return ['month' => $month, 'places' => 0, 'occupied' => 0, 'rate' => null];
            }

            $stayedThisMonth = $stays->filter(fn ($s) => $s->moved_in_on <= $end && ($s->moved_out_on === null || $s->moved_out_on > $start->toDateString()))
                ->pluck('unit_id')->flip();
            $places = $units->filter(fn ($u) => ! $u->is_whole_option
                && (substr((string) $u->created_at, 0, 10) <= $end || $stayedThisMonth->has($u->id))
                && ($u->deleted_at === null || substr((string) $u->deleted_at, 0, 10) > $end));

            $taken = $stays->filter(fn ($s) => $s->moved_in_on <= $end && ($s->moved_out_on === null || $s->moved_out_on > $end))
                ->flatMap(fn ($s) => $byId[$s->unit_id]->is_whole_option
                    ? $places->where('room_id', $byId[$s->unit_id]->room_id)->pluck('id')
                    : [$s->unit_id])
                ->unique()
                ->intersect($places->pluck('id'));

            return [
                'month' => $month,
                'places' => $places->count(),
                'occupied' => $taken->count(),
                'rate' => $places->isEmpty() ? null : round($taken->count() / $places->count(), 4),
            ];
        })->all();
    }

    /**
     * Collected per property: the same figure as `collected.total`, for the dashboard.
     *
     * @param  list<int>  $propertyIds
     * @return array<int, int>
     */
    public function collected(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $period = [$from->toDateString(), $to->toDateString()];
        $payments = $this->payments($propertyIds, $period, self::BY_PROPERTY, PaymentStatus::Confirmed, false);
        $firstRent = $this->moveInPayments($propertyIds, $period, self::BY_PROPERTY, TenancyPaymentKind::FirstRent);

        return collect($propertyIds)->mapWithKeys(fn (int $id) => [$id => ($payments[$id] ?? 0) + ($firstRent[$id] ?? 0)])->all();
    }

    /**
     * Add up several groups' figures (e.g. every property into the portfolio).
     *
     * @param  iterable<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    public function total(iterable $groups): array
    {
        $sum = null;
        foreach ($groups as $figures) {
            $sum = $sum === null ? $figures : $this->add($sum, $figures);
        }
        $sum ??= $this->none();
        $sum['collection']['rate'] = $this->rate($sum['collection']['paid'], $sum['collection']['due']);

        return $sum;
    }

    /**
     * All zeros, for a property or room with nothing in the period.
     *
     * @return array<string, mixed>
     */
    public function none(string $by = self::BY_PROPERTY): array
    {
        return $this->figures([], 0, $by === self::BY_PROPERTY);
    }

    /**
     * @param  array<string, array<int|string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function figures(array $parts, int|string $key, bool $byProperty): array
    {
        $billed = array_fill_keys(array_values(self::BILLED_KINDS), 0);
        foreach ($parts['billed'][$key] ?? [] as $kind => $centavos) {
            $billed[$kind] += $centavos;
        }
        $billed['rent'] += $parts['first_rent'][$key] ?? 0;
        $billed['total'] = array_sum($billed);

        $payments = $parts['payments'][$key] ?? 0;
        $firstRent = $parts['first_rent'][$key] ?? 0;
        $due = $parts['due'][$key] ?? 0;
        $paid = $parts['paid_of_due'][$key] ?? 0;
        $outstanding = $parts['outstanding'][$key] ?? null;
        $settled = $parts['settlements'][$key] ?? ['refunded' => 0, 'kept' => 0];
        $provider = $parts['provider'][$key] ?? ['cost' => 0, 'waived' => 0];
        $occupancy = $parts['occupancy'][$key] ?? ['places' => 0, 'occupied' => 0];

        return [
            'billed' => $billed,
            'collected' => [
                'payments' => $payments,
                'first_rent' => $firstRent,
                'total' => $payments + $firstRent,
                'unapplied' => $byProperty ? ($parts['unapplied'][$key] ?? 0) : null,
            ],
            'paid_from_deposit' => $parts['from_deposit'][$key] ?? 0,
            'collection' => ['due' => $due, 'paid' => $paid, 'rate' => $this->rate($paid, $due)],
            'outstanding' => $outstanding ?? $this->noOutstanding(),
            'credit_held' => $byProperty ? ($parts['credit'][$key] ?? 0) : null,
            'deposits' => [
                'received' => $parts['deposits_received'][$key] ?? 0,
                'held' => $parts['deposits_held'][$key] ?? 0,
                'refunded' => $settled['refunded'],
                'kept' => $settled['kept'],
            ],
            'utilities' => [
                'billed' => $billed['fixed_utilities'] + $billed['utility_shares'],
                'provider_cost' => $byProperty ? $provider['cost'] : null,
                'waived' => $byProperty ? $provider['waived'] : null,
            ],
            'repair_costs' => $parts['repairs'][$key] ?? 0,
            'occupancy' => $occupancy,
        ];
    }

    /**
     * @param  list<string>  $period
     * @return array<int|string, array<string, int>> key => figure name => centavos
     */
    private function billed(array $propertyIds, array $period, string $by): array
    {
        $lines = fn () => DB::table('bill_lines')->join('bills', 'bills.id', '=', 'bill_lines.bill_id')->whereIn('bills.property_id', $propertyIds);
        $issuedKey = $this->key($by, 'bills.property_id', 'bills.room_id', 'bills.issued_on');
        $addedKey = $this->key($by, 'bills.property_id', 'bills.room_id', 'bill_lines.created_at');

        $rows = $lines()->where('bill_lines.kind', '!=', BillLineKind::Adjustment->value)
            ->whereBetween('bills.issued_on', $period)
            ->selectRaw("{$issuedKey} as k, bill_lines.kind, sum(bill_lines.amount_centavos) as c")
            ->groupByRaw("{$issuedKey}, bill_lines.kind")->get()
            ->concat($lines()->where('bill_lines.kind', BillLineKind::Adjustment->value)
                ->whereRaw('bill_lines.created_at::date between ? and ?', $period)
                ->selectRaw("{$addedKey} as k, bill_lines.kind, sum(bill_lines.amount_centavos) as c")
                ->groupByRaw($addedKey)->groupBy('bill_lines.kind')->get());

        $billed = [];
        foreach ($rows as $row) {
            $name = self::BILLED_KINDS[$row->kind];
            $billed[$row->k][$name] = ($billed[$row->k][$name] ?? 0) + (int) $row->c;
        }

        return $billed;
    }

    /**
     * Confirmed payments by the day paid: real money ($fromDeposit false) or
     * the deposit applied at settlement. By room, through where they were applied.
     *
     * @param  list<string>  $period
     * @return array<int|string, int>
     */
    private function payments(array $propertyIds, array $period, string $by, PaymentStatus $status, bool $fromDeposit): array
    {
        $query = DB::table('payments')->whereIn('payments.property_id', $propertyIds)
            ->where('payments.status', $status->value)
            ->where('payments.method', $fromDeposit ? '=' : '!=', PaymentMethod::Deposit->value)
            ->whereBetween('payments.paid_on', $period);

        if ($by === self::BY_ROOM || $by === self::BY_ROOM_MONTH) {
            $query->join('payment_allocations as pa', 'pa.payment_id', '=', 'payments.id')
                ->join('bill_lines', 'bill_lines.id', '=', 'pa.bill_line_id')
                ->join('bills', 'bills.id', '=', 'bill_lines.bill_id');

            return $this->sum($query, $this->key($by, '', 'bills.room_id', 'payments.paid_on'), 'pa.amount_centavos');
        }

        return $this->sum($query, $this->key($by, 'payments.property_id', '', 'payments.paid_on'), 'payments.amount_centavos');
    }

    /**
     * Money paid in the period that is not applied to any bill yet (credit).
     *
     * @param  list<string>  $period
     * @return array<int, int>
     */
    private function unapplied(array $propertyIds, array $period): array
    {
        $paid = $this->payments($propertyIds, $period, self::BY_PROPERTY, PaymentStatus::Confirmed, false);
        $applied = $this->sum(DB::table('payments')->join('payment_allocations as pa', 'pa.payment_id', '=', 'payments.id')
            ->whereIn('payments.property_id', $propertyIds)
            ->where('payments.status', PaymentStatus::Confirmed->value)
            ->where('payments.method', '!=', PaymentMethod::Deposit->value)
            ->whereBetween('payments.paid_on', $period), 'payments.property_id', 'pa.amount_centavos');

        return collect($paid)->map(fn (int $c, $k) => $c - ($applied[$k] ?? 0))->all();
    }

    /**
     * @param  list<string>  $period
     * @return array<int|string, int>
     */
    private function moveInPayments(array $propertyIds, array $period, string $by, TenancyPaymentKind $kind): array
    {
        $query = DB::table('tenancy_payments')->join('tenancies', 'tenancies.id', '=', 'tenancy_payments.tenancy_id')
            ->whereIn('tenancies.property_id', $propertyIds)
            ->where('tenancy_payments.kind', $kind->value)
            ->whereBetween('tenancy_payments.received_on', $period);

        return $this->sum($query, $this->key($by, 'tenancies.property_id', 'tenancies.room_id', 'tenancy_payments.received_on'), 'tenancy_payments.amount_centavos');
    }

    /**
     * Charge lines of bills due in the period.
     *
     * @param  list<string>  $period
     * @return array<int|string, int>
     */
    private function due(array $propertyIds, array $period, string $by): array
    {
        return $this->sum($this->dueLines($propertyIds, $period), $this->key($by, 'bills.property_id', 'bills.room_id', 'bills.due_on'), 'bill_lines.amount_centavos');
    }

    /**
     * What has been applied so far (payments and credits) to the bills due in the period.
     *
     * @param  list<string>  $period
     * @return array<int|string, int>
     */
    private function paidOfDue(array $propertyIds, array $period, string $by): array
    {
        $query = $this->dueLines($propertyIds, $period)->join('payment_allocations as pa', 'pa.bill_line_id', '=', 'bill_lines.id');

        return $this->sum($query, $this->key($by, 'bills.property_id', 'bills.room_id', 'bills.due_on'), 'pa.amount_centavos');
    }

    /** @param  list<string>  $period */
    private function dueLines(array $propertyIds, array $period): Builder
    {
        return DB::table('bill_lines')->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
            ->whereIn('bills.property_id', $propertyIds)
            ->where('bill_lines.amount_centavos', '>', 0)
            ->whereBetween('bills.due_on', $period);
    }

    /**
     * Unpaid balances today, the same rows as the overdue list and the
     * Outstanding balances report.
     *
     * @return array<int, array<string, mixed>>
     */
    private function outstanding(array $propertyIds, string $by): array
    {
        $today = ManilaDate::today();
        $out = [];
        foreach (Bill::whereIn('property_id', $propertyIds)->withBalance()->unpaid()->get() as $bill) {
            $key = $by === self::BY_ROOM ? (int) $bill->room_id : $bill->property_id;
            $out[$key] ??= $this->noOutstanding();
            $balance = $bill->balanceCentavos();
            $out[$key]['total'] += $balance;
            if ($bill->isUnderReview()) {
                $out[$key]['under_review'] += $balance;

                continue;
            }
            $days = $bill->due_on->lt($today) ? (int) $bill->due_on->diffInDays($today) : 0;
            if ($days > 0) {
                $out[$key]['overdue'] += $balance;
            }
            $out[$key]['ageing'][match (true) {
                $days === 0 => 'not_due',
                $days <= 30 => 'days_1_30',
                $days <= 60 => 'days_31_60',
                $days <= 90 => 'days_61_90',
                default => 'over_90',
            }] += $balance;
        }

        return $out;
    }

    /** @return array{total: int, overdue: int, under_review: int, ageing: array<string, int>} */
    private function noOutstanding(): array
    {
        return ['total' => 0, 'overdue' => 0, 'under_review' => 0,
            'ageing' => ['not_due' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0]];
    }

    /**
     * Credit held today: confirmed money and credit lines not yet applied,
     * the same as the payment ledger's credit added up over payers.
     *
     * @return array<int, int>
     */
    private function credit(array $propertyIds): array
    {
        $payments = fn () => DB::table('payments')->whereIn('payments.property_id', $propertyIds)->where('payments.status', PaymentStatus::Confirmed->value);
        $creditLines = fn () => DB::table('bill_lines')->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
            ->whereIn('bills.property_id', $propertyIds)->where('bill_lines.amount_centavos', '<', 0);

        $paid = $this->sum($payments(), 'payments.property_id', 'payments.amount_centavos');
        $paidApplied = $this->sum($payments()->join('payment_allocations as pa', 'pa.payment_id', '=', 'payments.id'), 'payments.property_id', 'pa.amount_centavos');
        $credits = $this->sum($creditLines(), 'bills.property_id', '-bill_lines.amount_centavos');
        $creditsApplied = $this->sum($creditLines()->join('payment_allocations as pa', 'pa.credit_line_id', '=', 'bill_lines.id'), 'bills.property_id', 'pa.amount_centavos');

        return collect($propertyIds)->mapWithKeys(fn (int $id) => [$id => ($paid[$id] ?? 0) - ($paidApplied[$id] ?? 0)
            + ($credits[$id] ?? 0) - ($creditsApplied[$id] ?? 0)])->all();
    }

    /**
     * Deposits of stays not settled yet.
     *
     * @return array<int|string, int>
     */
    private function depositsHeld(array $propertyIds, string $by): array
    {
        $query = DB::table('tenancy_payments')->join('tenancies', 'tenancies.id', '=', 'tenancy_payments.tenancy_id')
            ->whereIn('tenancies.property_id', $propertyIds)
            ->where('tenancy_payments.kind', TenancyPaymentKind::Deposit->value)
            ->whereNotExists(fn ($q) => $q->from('settlements')->whereColumn('settlements.tenancy_id', 'tenancies.id'));

        return $this->sum($query, $this->key($by, 'tenancies.property_id', 'tenancies.room_id', ''), 'tenancy_payments.amount_centavos');
    }

    /**
     * Refunded (by refund day, else settlement day) and kept (by settlement day) in the period.
     *
     * @param  list<string>  $period
     * @return array<int|string, array{refunded: int, kept: int}>
     */
    private function settlements(array $propertyIds, array $period, string $by): array
    {
        $base = fn () => DB::table('settlements')->join('tenancies', 'tenancies.id', '=', 'settlements.tenancy_id')
            ->whereIn('tenancies.property_id', $propertyIds);
        $key = $this->key($by, 'tenancies.property_id', 'tenancies.room_id', '');

        $refunded = $this->sum($base()->whereRaw('coalesce(settlements.refunded_on, settlements.created_at::date) between ? and ?', $period), $key, 'settlements.refund_centavos');
        $kept = $this->sum($base()->whereRaw('settlements.created_at::date between ? and ?', $period), $key, 'settlements.kept_centavos');

        return collect(array_keys($refunded + $kept))
            ->mapWithKeys(fn ($k) => [$k => ['refunded' => $refunded[$k] ?? 0, 'kept' => $kept[$k] ?? 0]])->all();
    }

    /**
     * What the owner paid providers (metered bills by coverage end, latest
     * correction only) and the shares they waived.
     *
     * @param  list<string>  $period
     * @return array<int, array{cost: int, waived: int}>
     */
    private function providerCost(array $propertyIds, array $period): array
    {
        $out = [];
        UtilityBill::whereIn('property_id', $propertyIds)
            ->whereBetween('coverage_to_on', $period)
            ->whereNotExists(fn ($q) => $q->from('utility_bills as newer')->whereColumn('newer.replaces_id', 'utility_bills.id'))
            ->get(['property_id', 'total_centavos', 'shares'])
            ->each(function (UtilityBill $u) use (&$out) {
                $out[$u->property_id] ??= ['cost' => 0, 'waived' => 0];
                $out[$u->property_id]['cost'] += $u->total_centavos;
                $out[$u->property_id]['waived'] += (int) collect($u->shares ?? [])->where('waived', true)->sum('amount_centavos');
            });

        return $out;
    }

    /**
     * Repair costs recorded, by the day fixed (else reported).
     *
     * @param  list<string>  $period
     * @return array<int|string, int>
     */
    private function repairCosts(array $propertyIds, array $period, string $by): array
    {
        $query = DB::table('maintenance_requests')->whereIn('property_id', $propertyIds)
            ->whereNotNull('repair_cost_centavos')
            ->whereRaw('coalesce(resolved_at, created_at)::date between ? and ?', $period);

        return $this->sum($query, $this->key($by, 'property_id', 'room_id', ''), 'repair_cost_centavos');
    }

    /**
     * Places today (bedspaces and whole rooms, a room's whole-room option not
     * counted) and how many are taken, as on the dashboard. While a room is
     * rented whole, its bedspaces are blocked and count as taken.
     *
     * @return array<int|string, array{places: int, occupied: int}>
     */
    private function occupancy(array $propertyIds, string $by): array
    {
        $key = $this->key($by, 'u.property_id', 'u.room_id', '');
        $taken = collect([UnitStatus::Occupied, UnitStatus::Leaving, UnitStatus::Overstaying])->map->value->all();
        $in = implode(',', array_fill(0, count($taken), '?'));

        return DB::table('rentable_units as u')
            ->leftJoin('rentable_units as opt', fn ($j) => $j->on('opt.room_id', '=', 'u.room_id')->where('opt.is_whole_option', true)->whereNull('opt.deleted_at'))
            ->whereIn('u.property_id', $propertyIds)->whereNull('u.deleted_at')->where('u.is_whole_option', false)
            ->selectRaw("{$key} as k, count(*) as places, sum(case when u.status in ({$in}) or (u.blocked and opt.status in ({$in})) then 1 else 0 end) as occupied", [...$taken, ...$taken])
            ->groupByRaw($key)->get()
            ->mapWithKeys(fn ($r) => [$r->k => ['places' => (int) $r->places, 'occupied' => (int) $r->occupied]])->all();
    }

    /** The SQL that groups rows: the property, the room (0 = none), the month of a date, or room and month. */
    private function key(string $by, string $property, string $room, string $date): string
    {
        return match ($by) {
            self::BY_PROPERTY => $property,
            self::BY_ROOM => "coalesce({$room}, 0)",
            self::BY_MONTH => "to_char({$date}, 'YYYY-MM')",
            self::BY_ROOM_MONTH => "coalesce({$room}, 0) || ':' || to_char({$date}, 'YYYY-MM')",
        };
    }

    /** @return array<int|string, int> */
    private function sum(Builder $query, string $key, string $amount): array
    {
        return $query->selectRaw("{$key} as k, sum({$amount}) as c")->groupByRaw($key)->pluck('c', 'k')
            ->map(fn ($c) => (int) $c)->all();
    }

    private function rate(int $paid, int $due): ?float
    {
        return $due > 0 ? round($paid / $due, 4) : null;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function add(array $a, array $b): array
    {
        foreach ($b as $name => $value) {
            $a[$name] = match (true) {
                is_array($value) => $this->add($a[$name] ?? [], $value),
                is_int($value) => ($a[$name] ?? 0) + $value,
                default => $a[$name] ?? $value,
            };
        }

        return $a;
    }
}
