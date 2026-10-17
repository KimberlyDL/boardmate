<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Authorization\PropertyAccess;
use App\Enums\PaymentMethod;
use App\Enums\PropertyAbility as A;
use App\Enums\TenancyStatus;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Services\Documents\Contracts\DocumentService;
use App\Services\Tenancies\SettlementService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Deposit settlement after move-out (C8): owner or Manager.
 *
 * @group Tenancies
 */
class SettlementController extends Controller
{
    use AuthorizesProperty;

    public function __construct(
        private readonly SettlementService $settlements,
        private readonly DocumentService $documents,
    ) {}

    /**
     * Settlement worksheet or result
     *
     * Before settling (staff): the deposit held, what is owed and the unpaid
     * bills, any credit, the suggested use of the deposit and refund, and the
     * rent now against the deposit. After settling: the settlement (staff and
     * the tenant).
     */
    public function show(Request $request, Tenancy $tenancy): JsonResponse
    {
        $isTenant = $tenancy->tenant_id === $request->user()->id;
        if (! $isTenant) {
            $this->authorizeProperty($request, $tenancy->property, A::ManageSettlements);
        }
        $settlement = $tenancy->settlement()->with('settler:id,name')->first();
        if ($settlement) {
            return ApiResponse::ok(['settled' => true] + $this->present($settlement));
        }
        abort_if($isTenant, 404);
        abort_unless($tenancy->status === TenancyStatus::Ended, 422, $tenancy->status->isCurrent() ? 'Record the move-out first.' : 'Nothing to settle.');

        return ApiResponse::ok(['settled' => false] + $this->settlements->worksheet($tenancy));
    }

    /**
     * Settle the deposit
     *
     * `deductions` (each `description`, `amount_centavos`, `reason`) become
     * one-time charges. The deposit then pays what is owed, oldest first.
     * `keep_centavos` of what is left may be kept with `keep_reason`; the rest
     * is refunded with any unused credit (`refund_method` required when there
     * is a refund). Anything not covered stays owed. Once per tenancy.
     */
    public function store(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ManageSettlements);
        $data = $request->validate([
            'deductions' => ['sometimes', 'array', 'max:20'],
            'deductions.*.description' => ['required', 'string', 'max:160'],
            'deductions.*.amount_centavos' => ['required', 'integer', 'min:1', 'max:100000000'],
            'deductions.*.reason' => ['required', 'string', 'max:255'],
            'keep_centavos' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'keep_reason' => ['nullable', 'string', 'max:255'],
            'refund_method' => ['nullable', Rule::enum(PaymentMethod::class)->only(PaymentMethod::manual())],
            'refund_reference' => ['nullable', 'string', 'max:100'],
            'refunded_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.ManilaDate::today()->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $settlement = $this->settlements->settle($tenancy, $data, $request->user());

        return ApiResponse::created(['settled' => true] + $this->present($settlement->load('settler:id,name')), 'Deposit settled.');
    }

    /**
     * Link to the settlement summary (PDF)
     *
     * For the tenant and the owner and caretakers.
     */
    public function summaryLink(Request $request, Tenancy $tenancy): JsonResponse
    {
        if ($tenancy->tenant_id !== $request->user()->id) {
            abort_unless(PropertyAccess::can($request->user(), A::View, $tenancy->property), 404);
        }
        $settlement = $tenancy->settlement;
        abort_unless($settlement, 404);

        return ApiResponse::ok([
            'url' => $this->documents->link('settlement', ['settlement' => $settlement->id]),
            'expires_in_minutes' => (int) config('dormlink.private_file_url_minutes'),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Settlement $s): array
    {
        return [
            'id' => $s->id,
            'deposit_held_centavos' => $s->deposit_held_centavos,
            'deductions' => $s->deductions ?? [],
            'deductions_centavos' => $s->deductions_centavos,
            'applied_centavos' => $s->applied_centavos,
            'kept_centavos' => $s->kept_centavos,
            'kept_reason' => $s->kept_reason,
            'refund_centavos' => $s->refund_centavos,
            'credit_returned_centavos' => $s->credit_returned_centavos,
            'still_owed_centavos' => $s->still_owed_centavos,
            'refund_method' => $s->refund_method?->value,
            'refund_method_label' => $s->refund_method?->label(),
            'refund_reference' => $s->refund_reference,
            'refunded_on' => $s->refunded_on?->toDateString(),
            'note' => $s->note,
            'settled_by' => $s->settler?->name,
            'settled_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
