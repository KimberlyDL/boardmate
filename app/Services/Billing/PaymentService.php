<?php

namespace App\Services\Billing;

use App\Enums\AuditEvent;
use App\Enums\DocumentType;
use App\Enums\FilePurpose;
use App\Enums\NotificationEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Files\Contracts\FileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Numbering\Contracts\NumberingService;
use App\Support\ManilaDate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments recorded by hand (C6). No payment gateway yet.
 *
 * - The owner or a caretaker records money they received: confirmed at once,
 *   with a numbered receipt. Reference no. and proof are optional.
 * - A boarder reports a payment with a reference no. or a screenshot. It stays
 *   Pending until the owner or a caretaker confirms it (receipt) or rejects it
 *   with a reason.
 * - A confirmed payment entered by mistake is reversed by the owner or a
 *   Manager: never deleted, it keeps its receipt number, and a reversal entry
 *   (`reverses_id`) records who, when and why.
 *
 * Confirmed money is applied by the PaymentLedger; any left over is credit.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly NumberingService $numbering,
        private readonly FileService $files,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    /**
     * Money the owner or a caretaker received: confirmed at once.
     *
     * @param  array{amount_centavos: int, method: string, paid_on: string, reference?: ?string, note?: ?string}  $data
     */
    public function record(Property $property, User $payer, array $data, ?UploadedFile $proof, User $by): Payment
    {
        $property->loadMissing('owner');

        $payment = DB::transaction(fn () => Payment::create($this->fields($property, $payer, $data, $proof) + [
            'status' => PaymentStatus::Confirmed,
            'submitted_by' => $by->id,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'receipt_number' => $this->numbering->next($property->owner, DocumentType::Receipt, ManilaDate::today()),
        ]));

        $this->ledger->settle($property->id, $payer->id);
        $this->audit->record(AuditEvent::PaymentRecorded, $payment, owner: $property->owner, note: $this->describe($payment, $payer));
        $this->tellPayerConfirmed($payment, $property, $payer);

        return $payment;
    }

    /**
     * A boarder says they paid: Pending until someone confirms it. Needs a
     * reference no. or a screenshot.
     *
     * @param  array{amount_centavos: int, method: string, paid_on: string, reference?: ?string, note?: ?string}  $data
     */
    public function report(Property $property, User $payer, array $data, ?UploadedFile $proof): Payment
    {
        if (blank($data['reference'] ?? null) && ! $proof) {
            throw ValidationException::withMessages(['reference' => 'Enter the reference no. or attach a screenshot of the payment.']);
        }

        $property->loadMissing('owner');
        $payment = DB::transaction(fn () => Payment::create($this->fields($property, $payer, $data, $proof) + [
            'status' => PaymentStatus::Pending,
            'submitted_by' => $payer->id,
        ]));

        $this->audit->record(AuditEvent::PaymentReported, $payment, owner: $property->owner, actor: $payer, note: $this->describe($payment, $payer));
        $this->notifications->send($property->paymentConfirmers(), NotificationEvent::PaymentReported, [
            'property_id' => $property->id,
            'property_name' => $property->name,
            'payer_name' => $payer->name,
            'amount' => Money::format($payment->amount_centavos),
            'method' => $payment->method->label(),
            'reference_reused' => $this->reusedReferences(collect([$payment]))[$payment->id] ?? false,
        ]);

        return $payment;
    }

    public function confirm(Payment $payment, User $by): Payment
    {
        $payment = DB::transaction(function () use ($payment, $by) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($payment);
            $payment->load('property.owner');
            $payment->update([
                'status' => PaymentStatus::Confirmed,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'receipt_number' => $this->numbering->next($payment->property->owner, DocumentType::Receipt, ManilaDate::today()),
            ]);

            return $payment;
        });

        $payment->load('payer');
        $this->ledger->settle($payment->property_id, $payment->payer_id);
        $this->audit->record(AuditEvent::PaymentConfirmed, $payment, owner: $payment->property->owner, note: $this->describe($payment, $payment->payer));
        $this->tellPayerConfirmed($payment, $payment->property, $payment->payer);

        return $payment;
    }

    public function reject(Payment $payment, string $reason, User $by): Payment
    {
        $payment = DB::transaction(function () use ($payment, $reason, $by) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($payment);
            $payment->update([
                'status' => PaymentStatus::Rejected,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'reject_reason' => $reason,
            ]);

            return $payment;
        });

        $payment->load('property.owner', 'payer');
        $this->audit->record(AuditEvent::PaymentRejected, $payment, owner: $payment->property->owner, reason: $reason,
            note: $this->describe($payment, $payment->payer));
        $this->notifications->send($payment->payer, NotificationEvent::PaymentRejected, [
            'property_name' => $payment->property->name,
            'amount' => Money::format($payment->amount_centavos),
            'paid_on' => $payment->paid_on->format('M j, Y'),
            'reason' => $reason,
        ]);

        return $payment;
    }

    /**
     * Undo a confirmed payment entered by mistake (wrong person, wrong amount).
     * Its allocations are removed, so the charges it paid are owed again
     * unless other credit covers them. Record the right payment afterwards.
     */
    public function reverse(Payment $payment, string $reason, User $by): Payment
    {
        $payment = DB::transaction(function () use ($payment, $reason, $by) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Confirmed || $payment->reverses_id) {
                throw ValidationException::withMessages(['payment' => 'Only a confirmed payment can be reversed.']);
            }

            $payment->allocations()->delete();
            $payment->update(['status' => PaymentStatus::Reversed]);
            Payment::create([
                'property_id' => $payment->property_id,
                'payer_id' => $payment->payer_id,
                'amount_centavos' => $payment->amount_centavos,
                'method' => $payment->method,
                'paid_on' => ManilaDate::today()->toDateString(),
                'note' => $reason,
                'status' => PaymentStatus::Reversed,
                'submitted_by' => $by->id,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'reverses_id' => $payment->id,
            ]);

            return $payment;
        });

        $payment->load('property.owner', 'payer');
        $this->ledger->settle($payment->property_id, $payment->payer_id);
        $this->audit->record(AuditEvent::PaymentReversed, $payment, owner: $payment->property->owner, reason: $reason,
            note: $this->describe($payment, $payment->payer));
        $this->notifications->send($payment->payer, NotificationEvent::PaymentReversed, [
            'property_name' => $payment->property->name,
            'amount' => Money::format($payment->amount_centavos),
            'receipt_number' => $payment->receipt_number,
            'reason' => $reason,
        ]);

        return $payment;
    }

    /**
     * Which of these payments carry a reference no. already used on another
     * payment to the same owner (a reused screenshot or a typo).
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, bool> payment id => reused
     */
    public function reusedReferences(Collection $payments): array
    {
        $payments = new EloquentCollection($payments->filter(fn (Payment $p) => filled($p->reference))->all());
        if ($payments->isEmpty()) {
            return [];
        }

        $payments->loadMissing('property:id,owner_id');
        $flags = [];
        foreach ($payments->groupBy(fn (Payment $p) => $p->property->owner_id) as $ownerId => $group) {
            $counts = Payment::query()
                ->whereIn('property_id', Property::withTrashed()->where('owner_id', $ownerId)->select('id'))
                ->whereNull('reverses_id')
                ->where('status', '!=', PaymentStatus::Rejected->value)
                ->whereIn(DB::raw('lower(reference)'), $group->map(fn (Payment $p) => mb_strtolower($p->reference))->unique()->values())
                ->selectRaw('lower(reference) as ref, count(*) as uses')
                ->groupBy('ref')
                ->pluck('uses', 'ref');
            foreach ($group as $p) {
                $flags[$p->id] = ($counts[mb_strtolower($p->reference)] ?? 0) > 1;
            }
        }

        return $flags;
    }

    private function assertPending(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages(['payment' => 'This payment was already '.mb_strtolower($payment->status->label()).'.']);
        }
    }

    /** @return array<string, mixed> */
    private function fields(Property $property, User $payer, array $data, ?UploadedFile $proof): array
    {
        return [
            'property_id' => $property->id,
            'payer_id' => $payer->id,
            'amount_centavos' => $data['amount_centavos'],
            'method' => PaymentMethod::from($data['method']),
            'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
            'proof_path' => $proof ? $this->files->store($proof, FilePurpose::PaymentProof) : null,
            'paid_on' => $data['paid_on'],
            'note' => $data['note'] ?? null,
        ];
    }

    private function tellPayerConfirmed(Payment $payment, Property $property, User $payer): void
    {
        $summary = $this->ledger->summary($property->id, $payer->id);
        $this->notifications->send($payer, NotificationEvent::PaymentConfirmed, [
            'property_name' => $property->name,
            'amount' => Money::format($payment->amount_centavos),
            'receipt_number' => $payment->receipt_number,
            'paid_on' => $payment->paid_on->format('M j, Y'),
            'owed' => $summary['owed_centavos'] > 0 ? Money::format($summary['owed_centavos']) : null,
            'credit' => $summary['credit_centavos'] > 0 ? Money::format($summary['credit_centavos']) : null,
        ]);
    }

    private function describe(Payment $payment, User $payer): string
    {
        return collect([
            $payment->receipt_number,
            $payer->name,
            Money::format($payment->amount_centavos),
            $payment->method->label(),
            $payment->reference ? "ref {$payment->reference}" : null,
        ])->filter()->implode(' · ');
    }
}
