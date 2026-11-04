<?php

namespace App\Services\Billing;

use App\Authorization\PropertyAccess;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PropertyAbility as A;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Support\ManilaDate;
use Illuminate\Support\Collection;

/**
 * The collections view (A11) for everyone who follows up payments (owner and
 * caretakers, Collectors included, `view_arrears`): what comes due this week,
 * who is behind and for how long, and the payments received over the last 7
 * days. It shows money coming in on bills, never income totals, costs or
 * deposits, and reuses the overdue list's figures (C7) so both always agree.
 */
class CollectionsSummary
{
    /** Who is behind, by the days their oldest overdue bill is late. */
    private const BEHIND = ['days_1_30' => 30, 'days_31_60' => 60, 'days_61_90' => 90, 'over_90' => PHP_INT_MAX];

    public function __construct(private readonly CollectionService $collections) {}

    /**
     * The properties where the person follows up payments, by name.
     *
     * @return Collection<int, Property>
     */
    public function properties(User $user): Collection
    {
        return Property::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'owner_id'])
            ->filter(fn (Property $p) => PropertyAccess::can($user, A::ViewArrears, $p))->values();
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @return array<string, mixed>
     */
    public function for(Collection $properties, User $viewer): array
    {
        $today = ManilaDate::today();
        $ids = $properties->pluck('id')->all();

        $rows = $properties->flatMap(fn (Property $p) => array_map(
            fn (array $row) => $row + ['property' => ['id' => $p->id, 'name' => $p->name]],
            $this->collections->overdue($p),
        ))->sortByDesc('days_overdue')->values();

        $due = Bill::whereIn('property_id', $ids)->withBalance()->unpaid()
            ->whereBetween('due_on', [$today->toDateString(), $today->addDays(6)->toDateString()])->get();

        $weekStart = $today->subDays(6);
        $received = Payment::whereIn('property_id', $ids)
            ->where('status', PaymentStatus::Confirmed)
            ->where('method', '!=', PaymentMethod::Deposit)
            ->whereBetween('paid_on', [$weekStart->toDateString(), $today->toDateString()])
            ->selectRaw('paid_on, count(*) as n, sum(amount_centavos) as c')->groupBy('paid_on')->get()
            ->keyBy(fn ($r) => substr((string) $r->paid_on, 0, 10));

        $byDay = collect(range(0, 6))->map(function (int $i) use ($weekStart, $received) {
            $day = $weekStart->addDays($i)->toDateString();

            return ['date' => $day, 'payments' => (int) ($received[$day]->n ?? 0), 'centavos' => (int) ($received[$day]->c ?? 0)];
        });

        $canConfirm = $properties->filter(fn (Property $p) => PropertyAccess::can($viewer, A::ConfirmPaymentProofs, $p))->pluck('id');

        return [
            'week' => ['from' => $weekStart->toDateString(), 'to' => $today->toDateString()],
            'received' => [
                'payments' => $byDay->sum('payments'),
                'centavos' => $byDay->sum('centavos'),
                'by_day' => $byDay->all(),
            ],
            'due_soon' => ['bills' => $due->count(), 'centavos' => (int) $due->sum(fn (Bill $b) => $b->balanceCentavos())],
            'overdue' => [
                'payers' => $rows->count(),
                'bills' => (int) $rows->sum('overdue_bills'),
                'centavos' => (int) $rows->sum('overdue_centavos'),
                'late_payers' => $rows->where('is_late', true)->count(),
                'under_review_bills' => (int) $rows->sum('under_review_bills'),
            ],
            'behind' => $this->behind($rows),
            'proofs_waiting' => $canConfirm->isEmpty() ? null
                : Payment::whereIn('property_id', $canConfirm)->where('status', PaymentStatus::Pending)->count(),
            'most_behind' => $rows->take(5)->map(fn (array $r) => [
                'property' => $r['property'],
                'payer' => $r['payer'],
                'room_label' => $r['room_label'],
                'overdue_centavos' => (int) $r['overdue_centavos'],
                'days_overdue' => $r['days_overdue'],
                'is_late' => $r['is_late'],
                'promised_on' => $r['latest_note']['promised_on'] ?? null,
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array{payers: int, centavos: int}>
     */
    private function behind(Collection $rows): array
    {
        $out = array_fill_keys(array_keys(self::BEHIND), ['payers' => 0, 'centavos' => 0]);
        foreach ($rows as $row) {
            foreach (self::BEHIND as $bucket => $upTo) {
                if ($row['days_overdue'] <= $upTo) {
                    $out[$bucket]['payers']++;
                    $out[$bucket]['centavos'] += (int) $row['overdue_centavos'];
                    break;
                }
            }
        }

        return $out;
    }
}
