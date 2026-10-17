<?php

namespace App\Services\Billing;

use App\Enums\AuditEvent;
use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\DocumentType;
use App\Enums\NotificationEvent;
use App\Models\Bill;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Numbering\Contracts\NumberingService;
use App\Support\ManilaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-time charges and corrections (C3, C4), by the owner or a Manager, never
 * automatic, always with a reason.
 *
 * - A one-time charge (damage, lost key, extra occupant) is its own numbered
 *   bill to the tenant, due on the day chosen (default: the property's utility
 *   due days from today).
 * - A correction is an adjustment line added to an issued bill: positive
 *   charges more, negative is a credit. A bill never goes below zero; to
 *   credit more, adjust another bill.
 */
class ChargeService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly PaymentLedger $ledger,
    ) {}

    /** `$notify` false: the caller tells the payer itself (e.g. one settlement message for all deductions). */
    public function charge(Tenancy $tenancy, int $amountCentavos, string $description, string $reason, ?string $dueOn, User $by, bool $notify = true): Bill
    {
        $tenancy->loadMissing('property.owner', 'property.settings', 'tenant');
        $property = $tenancy->property;
        $today = ManilaDate::today();
        $due = $dueOn ? CarbonImmutable::parse($dueOn, 'Asia/Manila') : $today->addDays((int) ($property->settings?->utility_due_days ?? 7));

        $bill = DB::transaction(function () use ($tenancy, $property, $amountCentavos, $description, $reason, $today, $due, $by) {
            $bill = Bill::create([
                'property_id' => $property->id,
                'payer_id' => $tenancy->tenant_id,
                'tenancy_id' => $tenancy->id,
                'room_id' => $tenancy->room_id,
                'kind' => BillKind::Charge,
                'number' => $this->numbering->next($property->owner, DocumentType::Bill, $today),
                'issued_on' => $today->toDateString(),
                'due_on' => $due->toDateString(),
                'issued_by' => $by->id,
            ]);
            $bill->lines()->create([
                'kind' => BillLineKind::OneTime,
                'description' => $description,
                'amount_centavos' => $amountCentavos,
                'reason' => $reason,
                'created_by' => $by->id,
                'sort_order' => 1,
            ]);

            return $bill;
        });

        $this->ledger->settle($bill->property_id, $bill->payer_id);
        $this->audit->record(AuditEvent::ChargeAdded, $bill, owner: $property->owner, reason: $reason,
            note: "{$bill->number} · {$tenancy->tenant->name} · {$description} · ".Money::format($amountCentavos));

        if ($notify) {
            $this->notifications->send($tenancy->tenant, NotificationEvent::BillIssued, [
                'property_name' => $property->name,
                'bill_number' => $bill->number,
                'total' => Money::format($amountCentavos),
                'due_on' => $bill->due_on->format('M j, Y'),
                'period' => $bill->issued_on->format('M j, Y'),
                'summary' => "a one-time charge: {$description} ({$reason})",
            ]);
        }

        return $bill;
    }

    /**
     * Add a correction to an issued bill. `$amountCentavos` is signed and not
     * zero; `$lineId` optionally names the line it corrects.
     */
    public function adjust(Bill $bill, int $amountCentavos, ?string $description, string $reason, ?int $lineId, User $by): Bill
    {
        if ($amountCentavos === 0) {
            throw ValidationException::withMessages(['amount_centavos' => 'Enter an amount to add (positive) or take off (negative).']);
        }

        DB::transaction(function () use ($bill, $amountCentavos, $description, $reason, $lineId, $by) {
            $bill = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $line = $lineId ? $bill->lines()->whereKey($lineId)->first() : null;
            if ($lineId && ! $line) {
                throw ValidationException::withMessages(['line_id' => 'Choose a line of this bill.']);
            }
            if ($bill->totalCentavos() + $amountCentavos < 0) {
                throw ValidationException::withMessages([
                    'amount_centavos' => 'A bill cannot go below ₱0. Its total is '.Money::format($bill->totalCentavos()).'; to credit more, adjust another bill.',
                ]);
            }

            $bill->lines()->create([
                'kind' => BillLineKind::Adjustment,
                'description' => $description ?: ($line ? "Correction to {$line->description}" : 'Correction'),
                'amount_centavos' => $amountCentavos,
                'adjusts_line_id' => $line?->id,
                'reason' => $reason,
                'created_by' => $by->id,
                'sort_order' => 50,
            ]);
        });

        $this->ledger->settle($bill->property_id, $bill->payer_id);
        $bill->load('property.owner', 'payer');
        $change = ($amountCentavos > 0 ? '+' : '').Money::format($amountCentavos);
        $this->audit->record(AuditEvent::BillAdjusted, $bill, ['amount' => [null, $change]], owner: $bill->property->owner, reason: $reason,
            note: "{$bill->number} · {$bill->payer->name} · {$change}");

        $this->notifications->send($bill->payer, NotificationEvent::BillAdjusted, [
            'property_name' => $bill->property->name,
            'bill_number' => $bill->number,
            'change' => $change,
            'total' => Money::format($bill->totalCentavos()),
            'reason' => $reason,
        ]);

        return $bill;
    }
}
