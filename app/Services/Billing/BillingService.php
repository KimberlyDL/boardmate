<?php

namespace App\Services\Billing;

use App\Enums\AuditEvent;
use App\Enums\BilledBy;
use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\DocumentType;
use App\Enums\NotificationEvent;
use App\Enums\TenancyStatus;
use App\Enums\UnitKind;
use App\Enums\UtilityMethod;
use App\Models\Bill;
use App\Models\PriceRule;
use App\Models\Tenancy;
use App\Models\UtilityAccount;
use App\Services\Audit\Contracts\AuditService;
use App\Services\BillingCalendar\BillingCalendar;
use App\Services\BillingCalendar\RentPeriod;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Numbering\Contracts\NumberingService;
use App\Services\Pricing\PriceBook;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Anniversary bills (C4). On each tenancy's due date it gets one bill:
 *
 * - rent for the period starting (in advance), at the price in force on that
 *   period's first day, minus the agreed-rate discount in force that day;
 * - fixed utilities for the period just finished (in arrears), at their price
 *   on that period's first day: "fixed per bedspace" on bedspace tenancies,
 *   "fixed per property" on whole-room tenancies; only accounts the owner
 *   bills, for the whole property or the tenancy's room.
 *
 * Period 1 rent was paid at move-in, so the first bill is period 2. Issuing
 * locks the prices and discount used and takes the bill number in the same
 * transaction. It works out what is due AS OF a day, so a rerun or a missed
 * day never duplicates or skips a bill (the database also refuses a second
 * bill for the same tenancy period).
 */
class BillingService
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly NumberingService $numbering,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    /**
     * Issue every anniversary bill due on or before $day that is missing.
     *
     * @return array{issued: int, skipped: list<string>}
     */
    public function issueDuePeriodicBills(CarbonImmutable $day): array
    {
        $issued = 0;
        $skipped = [];

        Tenancy::query()
            ->where('status', TenancyStatus::Active->value)
            ->where('moved_in_on', '<', $day->toDateString())
            ->with(['property.owner', 'unit', 'room', 'tenant'])
            ->chunkById(100, function (Collection $tenancies) use ($day, &$issued, &$skipped) {
                foreach ($tenancies as $tenancy) {
                    $billed = $tenancy->bills()->where('kind', BillKind::Periodic->value)
                        ->pluck('period_start_on')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->flip();

                    $previous = null;
                    foreach (BillingCalendar::periods($tenancy->moved_in_on, $tenancy->anchor_day) as $period) {
                        if ($period->dueOn->greaterThan($day)) {
                            break;
                        }
                        if ($period->number >= 2 && ! $billed->has($period->startsOn->toDateString())) {
                            $bill = $this->issuePeriodic($tenancy, $period, $previous, $day);
                            $bill ? $issued++ : $skipped[] = "{$tenancy->tenant->name} ({$tenancy->property->name}): no rent set for ".$period->startsOn->format('M j, Y');
                        }
                        $previous = $period;
                    }
                }
            });

        return ['issued' => $issued, 'skipped' => $skipped];
    }

    /**
     * One tenancy's bill for one period, or null when there is no rent price to
     * bill (nothing is issued; the job reports it) or it already exists.
     */
    public function issuePeriodic(Tenancy $tenancy, RentPeriod $period, ?RentPeriod $previous, CarbonImmutable $day): ?Bill
    {
        $rentRule = $this->prices->ruleOn($tenancy->unit, $period->startsOn);
        if (! $rentRule) {
            return null;
        }

        try {
            $bill = DB::transaction(function () use ($tenancy, $period, $previous, $day, $rentRule) {
                Tenancy::whereKey($tenancy->id)->lockForUpdate()->first();
                $exists = Bill::where('tenancy_id', $tenancy->id)->where('kind', BillKind::Periodic->value)
                    ->whereDate('period_start_on', $period->startsOn->toDateString())->exists();
                if ($exists) {
                    return null;
                }

                $bill = Bill::create([
                    'property_id' => $tenancy->property_id,
                    'payer_id' => $tenancy->tenant_id,
                    'tenancy_id' => $tenancy->id,
                    'room_id' => $tenancy->room_id,
                    'kind' => BillKind::Periodic,
                    'number' => $this->numbering->next($tenancy->property->owner, DocumentType::Bill, $day),
                    'period_start_on' => $period->startsOn->toDateString(),
                    'period_end_on' => $period->endsOn->toDateString(),
                    'issued_on' => $day->toDateString(),
                    'due_on' => $period->dueOn->toDateString(),
                ]);

                $locked = collect([$rentRule]);
                $this->addRentLine($bill, $tenancy, $period, $rentRule);
                if ($previous) {
                    $locked = $locked->merge($this->addFixedUtilityLines($bill, $tenancy, $previous));
                }
                $this->prices->lock($locked);

                return $bill;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return null; // another run issued it first
        }

        if ($bill) {
            $this->announce($bill->load('lines'), $tenancy);
        }

        return $bill;
    }

    private function addRentLine(Bill $bill, Tenancy $tenancy, RentPeriod $period, PriceRule $rule): void
    {
        $discount = $tenancy->discounts()->inForceOn($period->startsOn->toDateString())->latest('effective_from')->first();
        $off = $discount?->amountOff($rule->amount_centavos) ?? 0;

        $bill->lines()->create([
            'kind' => BillLineKind::Rent,
            'description' => "Rent, {$this->span($period)}".($off > 0 ? ' (agreed rate, '.Money::format($off).' off)' : ''),
            'amount_centavos' => $rule->amount_centavos - $off,
            'unit_id' => $tenancy->unit_id,
            'price_rule_id' => $rule->id,
            'discount_id' => $off > 0 ? $discount->id : null,
            'sort_order' => 1,
        ]);

        if ($off > 0 && $discount->locked_at === null) {
            $discount->forceFill(['locked_at' => now()])->save();
        }
    }

    /**
     * Fixed utilities for the period just finished.
     *
     * @return Collection<int, PriceRule> the prices used
     */
    private function addFixedUtilityLines(Bill $bill, Tenancy $tenancy, RentPeriod $finished): Collection
    {
        $method = $tenancy->unit->kind === UnitKind::Whole ? UtilityMethod::FixedPerProperty : UtilityMethod::FixedPerBedspace;
        $accounts = UtilityAccount::query()
            ->where('property_id', $tenancy->property_id)
            ->where('method', $method->value)
            ->where('billed_by', BilledBy::Owner->value)
            ->where(fn ($q) => $q->whereNull('room_id')->orWhere('room_id', $tenancy->room_id))
            ->orderBy('id')
            ->get();

        $used = collect();
        foreach ($accounts as $i => $account) {
            $rule = $this->prices->ruleOn($account, $finished->startsOn);
            if (! $rule || $rule->amount_centavos <= 0) {
                continue;
            }
            $bill->lines()->create([
                'kind' => BillLineKind::FixedUtility,
                'description' => "{$account->name}, {$this->span($finished)}",
                'amount_centavos' => $rule->amount_centavos,
                'price_rule_id' => $rule->id,
                'utility_account_id' => $account->id,
                'sort_order' => 10 + $i,
            ]);
            $used->push($rule);
        }

        return $used;
    }

    private function announce(Bill $bill, Tenancy $tenancy): void
    {
        $total = $bill->totalCentavos();
        $property = $tenancy->property;

        $this->audit->record(AuditEvent::BillIssued, $bill, owner: $property->owner,
            note: "{$bill->number} · {$tenancy->tenant->name} · {$property->name} · ".Money::format($total).' due '.$bill->due_on->format('M j, Y'));

        $this->notifications->send($tenancy->tenant, NotificationEvent::BillIssued, [
            'property_name' => $property->name,
            'bill_number' => $bill->number,
            'total' => Money::format($total),
            'due_on' => $bill->due_on->format('M j, Y'),
            'period' => $bill->period_start_on?->format('M j').' – '.$bill->period_end_on?->format('M j, Y'),
        ]);
    }

    private function span(RentPeriod $period): string
    {
        return $period->startsOn->format('M j').' – '.$period->endsOn->format('M j, Y');
    }
}
