<?php

namespace App\Services\Tenancies;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TenancyPaymentKind;
use App\Enums\TenancyStatus;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Billing\ChargeService;
use App\Services\Billing\PaymentLedger;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Pricing\PriceBook;
use App\Support\ManilaDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deposit settlement after move-out (C8, SC-17, SC-18, SC-25), once per
 * tenancy, by the owner or a Manager.
 *
 * 1. Deductions (damage, cleaning…) become one-time charges with a reason.
 * 2. The deposit pays what is owed at the property, oldest first, as a
 *    payment with method "Deposit" (no receipt).
 * 3. Of what is left, the owner may keep part with a reason (never
 *    automatic); the rest is refunded, together with any unused credit.
 * 4. Anything the deposit did not cover stays owed on the bills (the overdue
 *    list follows it up).
 *
 * The numbers are worked out and checked before anything is written, so a
 * refused settlement leaves no charges or messages behind.
 */
class SettlementService
{
    public function __construct(
        private readonly ChargeService $charges,
        private readonly PaymentLedger $ledger,
        private readonly PriceBook $prices,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    /**
     * What settling would look like now (no deductions, nothing kept): the
     * deposit, what is owed and the bills, credit, and the rent now against
     * the deposit (SC-18: a raise after the deposit shows as a gap the owner
     * may collect as a deduction).
     *
     * @return array<string, mixed>
     */
    public function worksheet(Tenancy $tenancy): array
    {
        $tenancy->loadMissing('unit');
        $plan = $this->plan($tenancy, 0, 0);
        $rentNow = $this->prices->amountOn($tenancy->unit, $tenancy->moved_out_on ?? ManilaDate::today());

        return [
            'deposit_held_centavos' => $plan['deposit'],
            'owed_centavos' => $plan['owed_before'],
            'credit_centavos' => $plan['credit_before'],
            'suggested_apply_centavos' => $plan['apply'],
            'suggested_refund_centavos' => $plan['refund'],
            'still_owed_centavos' => $plan['still_owed'],
            'rent_now_centavos' => $rentNow,
            'deposit_gap_centavos' => $rentNow !== null ? max(0, $rentNow - $plan['deposit']) : 0,
            'unpaid_bills' => Bill::where('property_id', $tenancy->property_id)->where('payer_id', $tenancy->tenant_id)
                ->withBalance()->unpaid()->orderBy('due_on')->orderBy('id')->get()
                ->map(fn (Bill $b) => ['id' => $b->id, 'number' => $b->number, 'kind_label' => $b->kind->label(), 'due_on' => $b->due_on->toDateString(), 'balance_centavos' => $b->balanceCentavos()])
                ->values()->all(),
        ];
    }

    /**
     * @param  array{deductions?: list<array{description: string, amount_centavos: int, reason: string}>, keep_centavos?: ?int, keep_reason?: ?string, refund_method?: ?string, refund_reference?: ?string, refunded_on?: ?string, note?: ?string}  $data
     */
    public function settle(Tenancy $tenancy, array $data, User $by): Settlement
    {
        $settlement = DB::transaction(function () use ($tenancy, $data, $by) {
            $tenancy = Tenancy::whereKey($tenancy->id)->lockForUpdate()->with(['property.owner', 'tenant'])->firstOrFail();
            if ($tenancy->status !== TenancyStatus::Ended) {
                throw ValidationException::withMessages(['tenancy' => $tenancy->status === TenancyStatus::Settled
                    ? 'This deposit is already settled.'
                    : 'Record the move-out first.']);
            }
            $deductions = $data['deductions'] ?? [];
            $keep = (int) ($data['keep_centavos'] ?? 0);
            $plan = $this->plan($tenancy, array_sum(array_column($deductions, 'amount_centavos')), $keep);

            // Check everything first.
            if ($keep > $plan['left']) {
                throw ValidationException::withMessages(['keep_centavos' => 'You can keep at most '.Money::format($plan['left']).', what is left of the deposit after the bills.']);
            }
            if ($keep > 0 && blank($data['keep_reason'] ?? null)) {
                throw ValidationException::withMessages(['keep_reason' => 'Say why you keep part of the deposit.']);
            }
            if ($plan['refund'] > 0 && blank($data['refund_method'] ?? null)) {
                throw ValidationException::withMessages(['refund_method' => 'Say how the refund of '.Money::format($plan['refund']).' is paid back.']);
            }

            $today = ManilaDate::today()->toDateString();

            // 1. Deductions, each its own one-time charge due today.
            $kept = [];
            foreach ($deductions as $d) {
                $bill = $this->charges->charge($tenancy, $d['amount_centavos'], $d['description'], $d['reason'], $today, $by, notify: false);
                $kept[] = ['bill_number' => $bill->number, 'description' => $d['description'], 'amount_centavos' => $d['amount_centavos'], 'reason' => $d['reason']];
            }

            // 2. The deposit pays what is owed, oldest first.
            $payment = null;
            if ($plan['apply'] > 0) {
                $payment = Payment::create([
                    'property_id' => $tenancy->property_id,
                    'payer_id' => $tenancy->tenant_id,
                    'amount_centavos' => $plan['apply'],
                    'method' => PaymentMethod::Deposit,
                    'paid_on' => $today,
                    'note' => 'Deposit applied at settlement',
                    'status' => PaymentStatus::Confirmed,
                    'submitted_by' => $by->id,
                    'decided_by' => $by->id,
                    'decided_at' => now(),
                ]);
                $this->ledger->settle($tenancy->property_id, $tenancy->tenant_id);
            }

            // 3. Unused credit goes back with the refund; using it up here stops it being counted again.
            if ($plan['credit_after'] > 0) {
                $this->charges->charge($tenancy, $plan['credit_after'], 'Credit returned at settlement', 'Unused credit paid back with the deposit refund', $today, $by, notify: false);
            }

            $settlement = Settlement::create([
                'tenancy_id' => $tenancy->id,
                'deposit_held_centavos' => $plan['deposit'],
                'deductions_centavos' => array_sum(array_column($kept, 'amount_centavos')),
                'deductions' => $kept,
                'applied_centavos' => $plan['apply'],
                'refund_centavos' => $plan['refund'],
                'credit_returned_centavos' => $plan['credit_after'],
                'kept_centavos' => $keep,
                'kept_reason' => $keep > 0 ? $data['keep_reason'] : null,
                'still_owed_centavos' => $plan['still_owed'],
                'payment_id' => $payment?->id,
                'refund_method' => $plan['refund'] > 0 ? $data['refund_method'] : null,
                'refund_reference' => $plan['refund'] > 0 ? ($data['refund_reference'] ?? null) : null,
                'refunded_on' => $plan['refund'] > 0 ? ($data['refunded_on'] ?? $today) : null,
                'note' => $data['note'] ?? null,
                'settled_by' => $by->id,
            ]);
            $tenancy->update(['status' => TenancyStatus::Settled]);

            return $settlement;
        });

        $tenancy->refresh()->load('property.owner', 'tenant');
        $this->audit->record(AuditEvent::DepositSettled, $settlement, owner: $tenancy->property->owner, reason: $settlement->kept_reason,
            note: "{$tenancy->tenant->name} · deposit ".Money::format($settlement->deposit_held_centavos)
                .' · applied '.Money::format($settlement->applied_centavos)
                .' · refund '.Money::format($settlement->refund_centavos)
                .($settlement->kept_centavos ? ' · kept '.Money::format($settlement->kept_centavos) : '')
                .($settlement->still_owed_centavos ? ' · still owed '.Money::format($settlement->still_owed_centavos) : ''));
        $this->notifications->send($tenancy->tenant, NotificationEvent::DepositSettled, [
            'property_name' => $tenancy->property->name,
            'deposit' => Money::format($settlement->deposit_held_centavos),
            'deductions' => array_map(fn (array $d) => "{$d['description']}: ".Money::format($d['amount_centavos'])." ({$d['reason']})", $settlement->deductions ?? []),
            'applied' => Money::format($settlement->applied_centavos),
            'refund' => $settlement->refund_centavos > 0 ? Money::format($settlement->refund_centavos) : null,
            'kept' => $settlement->kept_centavos > 0 ? Money::format($settlement->kept_centavos) : null,
            'kept_reason' => $settlement->kept_reason,
            'still_owed' => $settlement->still_owed_centavos > 0 ? Money::format($settlement->still_owed_centavos) : null,
        ]);

        return $settlement;
    }

    /**
     * The settlement's numbers, without writing anything. The ledger nets
     * what is owed against credit, so after the deductions the payer either
     * owes (`owed_after`) or holds credit (`credit_after`), never both.
     *
     * @return array{deposit: int, owed_before: int, credit_before: int, owed_after: int, credit_after: int, apply: int, left: int, refund: int, still_owed: int}
     */
    private function plan(Tenancy $tenancy, int $deductions, int $keep): array
    {
        $deposit = $tenancy->paidCentavos(TenancyPaymentKind::Deposit);
        $standing = $this->ledger->summary($tenancy->property_id, $tenancy->tenant_id);
        $net = $standing['owed_centavos'] - $standing['credit_centavos'] + $deductions;
        $owedAfter = max(0, $net);
        $creditAfter = max(0, -$net);
        $apply = min($deposit, $owedAfter);
        $left = $deposit - $apply;

        return [
            'deposit' => $deposit,
            'owed_before' => $standing['owed_centavos'],
            'credit_before' => $standing['credit_centavos'],
            'owed_after' => $owedAfter,
            'credit_after' => $creditAfter,
            'apply' => $apply,
            'left' => $left,
            'refund' => max(0, $left - $keep) + $creditAfter,
            'still_owed' => $owedAfter - $apply,
        ];
    }
}
