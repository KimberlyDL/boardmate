<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\FilePurpose;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\BillResource;
use App\Http\Resources\Billing\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Billing\PaymentService;
use App\Services\Files\Contracts\FileService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payments recorded by hand (no payment gateway yet).
 *
 * @group Billing
 */
class PaymentController extends Controller
{
    use AuthorizesProperty;

    private const WITH = ['payer:id,name', 'submitter:id,name', 'decider:id,name', 'reversal.submitter:id,name'];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentLedger $ledger,
        private readonly FileService $files,
    ) {}

    /**
     * List a property's payments
     *
     * Newest first; `status` (pending first, oldest first) and `payer_id`
     * filter. Each payment says whether its reference no. was used on another
     * payment to the same owner (`reference_reused`). Owner and caretakers.
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::RecordPayments);
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'payer_id' => ['nullable', 'integer'],
        ]);
        $status = PaymentStatus::tryFrom($data['status'] ?? '');

        $base = Payment::where('property_id', $property->id)->whereNull('reverses_id');
        $page = (clone $base)
            ->with(self::WITH)
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->when($data['payer_id'] ?? null, fn ($q, $id) => $q->where('payer_id', $id))
            ->orderBy('id', $status === PaymentStatus::Pending ? 'asc' : 'desc')
            ->paginate(25);

        $reused = $this->payments->reusedReferences(collect($page->items()));

        return ApiResponse::ok(
            collect($page->items())->map(fn (Payment $p) => new PaymentResource($p, $reused[$p->id] ?? false)),
            meta: [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'pending_count' => (clone $base)->where('status', PaymentStatus::Pending->value)->count(),
            ],
        );
    }

    /**
     * Record a payment received
     *
     * By the owner or a caretaker: confirmed at once with a numbered receipt
     * and applied to the payer's oldest charges (rent, then utilities, then
     * other charges on the same due date); any extra is credit. `reference`
     * and `proof` (jpg/png/webp/pdf, up to 5 MB) are optional.
     */
    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::RecordPayments);
        $data = $request->validate($this->rules($property, staff: true), $this->messages());

        $payment = $this->payments->record($property, User::findOrFail($data['payer_id']), $data, $request->file('proof'), $request->user());

        return ApiResponse::created($this->present($payment), "Payment recorded. Receipt {$payment->receipt_number}.");
    }

    /**
     * What a payer owes here
     *
     * Their unpaid and partly paid bills, oldest first, with the total owed
     * and any credit. For the record-payment screen.
     */
    public function balance(Request $request, Property $property, User $payer): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::RecordPayments);

        return ApiResponse::ok($this->standing($property->id, $payer->id));
    }

    /**
     * Confirm a reported payment
     *
     * Gives it a receipt number and applies it like a recorded payment.
     */
    public function confirm(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizeProperty($request, $payment->property, A::ConfirmPaymentProofs);
        $payment = $this->payments->confirm($payment, $request->user());

        return ApiResponse::ok($this->present($payment), "Payment confirmed. Receipt {$payment->receipt_number}.");
    }

    /**
     * Reject a reported payment
     *
     * `reason` is required and sent to the boarder; their bills stay unpaid.
     */
    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizeProperty($request, $payment->property, A::ConfirmPaymentProofs);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::ok($this->present($this->payments->reject($payment, $data['reason'], $request->user())), 'Payment rejected.');
    }

    /**
     * Reverse a confirmed payment
     *
     * For a mistake (wrong person or amount). Owner or Manager, with a
     * reason. The payment and its receipt number stay on record as Reversed;
     * the charges it paid are owed again. Record the right payment afterwards.
     */
    public function reverse(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizeProperty($request, $payment->property, A::EditIssuedBills);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::ok($this->present($this->payments->reverse($payment, $data['reason'], $request->user())), 'Payment reversed.');
    }

    /**
     * My payments
     *
     * Everything the signed-in boarder paid or reported, newest first.
     */
    public function mine(Request $request): JsonResponse
    {
        $page = Payment::where('payer_id', $request->user()->id)->whereNull('reverses_id')
            ->with([...self::WITH, 'property:id,name'])
            ->latest('id')
            ->paginate(25);

        return ApiResponse::ok(
            collect($page->items())->map(fn (Payment $p) => new PaymentResource($p)),
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        );
    }

    /**
     * Report a payment I made
     *
     * For a bank transfer or e-wallet payment to a place where I stay or
     * stayed. Needs `reference` or `proof` (a screenshot). Stays Pending until
     * the owner or a caretaker confirms it.
     */
    public function report(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'property_id' => ['required', 'integer', Rule::exists('tenancies', 'property_id')->where('tenant_id', $user->id)],
            ...$this->rules(null, staff: false),
        ], ['property_id.exists' => 'You can only report payments to a place where you stay.']);

        $payment = $this->payments->report(Property::findOrFail($data['property_id']), $user, $data, $request->file('proof'));

        return ApiResponse::created($this->present($payment), 'Payment reported. The owner will confirm it.');
    }

    private function present(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->fresh(self::WITH));
    }

    /** @return array{owed_centavos: int, credit_centavos: int, bills: mixed} */
    private function standing(int $propertyId, int $payerId): array
    {
        $bills = Bill::where('property_id', $propertyId)->where('payer_id', $payerId)
            ->withBalance()->unpaid()
            ->with(['lines', 'payer:id,name', 'room'])
            ->orderBy('due_on')->orderBy('id')
            ->get();

        return $this->ledger->summary($propertyId, $payerId) + ['bills' => BillResource::collection($bills)];
    }

    private function rules(?Property $property, bool $staff): array
    {
        $rules = [
            'amount_centavos' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)->only(PaymentMethod::manual())],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.ManilaDate::today()->toDateString()],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', ...array_slice($this->files->rules(FilePurpose::PaymentProof), 1)],
        ];

        if ($staff) {
            $rules['payer_id'] = ['required', 'integer', Rule::exists(Tenancy::class, 'tenant_id')->where('property_id', $property->id)];
        }

        return $rules;
    }

    private function messages(): array
    {
        return ['payer_id.exists' => 'Choose someone who stays or stayed here.'];
    }
}
