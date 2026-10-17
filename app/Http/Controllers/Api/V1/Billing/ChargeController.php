<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\BillResource;
use App\Http\Responses\ApiResponse;
use App\Models\Bill;
use App\Models\Property;
use App\Models\Tenancy;
use App\Services\Billing\ChargeService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One-time charges and corrections to issued bills (owner or Manager).
 *
 * @group Billing
 */
class ChargeController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly ChargeService $charges) {}

    /**
     * Add a one-time charge
     *
     * Damage, lost key, extra occupant… A numbered bill to the tenant of
     * `tenancy_id`, due on `due_on` (default: the property's utility due days
     * from today). `reason` is required and shown to the tenant.
     */
    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::EditIssuedBills);
        $data = $request->validate([
            // Any tenancy here, moved-out ones included (e.g. a final utility bill that came later, C8).
            'tenancy_id' => ['required', 'integer', Rule::exists('tenancies', 'id')->where('property_id', $property->id)],
            'amount_centavos' => ['required', 'integer', 'min:1', 'max:100000000'],
            'description' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:255'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.ManilaDate::today()->toDateString()],
        ], ['tenancy_id.exists' => 'Choose someone who stays or stayed here.']);

        $bill = $this->charges->charge(Tenancy::findOrFail($data['tenancy_id']), $data['amount_centavos'], $data['description'],
            $data['reason'], $data['due_on'] ?? null, $request->user());

        return ApiResponse::created(new BillResource($bill->load(['lines', 'payer', 'room'])), 'Charge added.');
    }

    /**
     * Correct an issued bill
     *
     * Adds an adjustment line: `amount_centavos` positive charges more,
     * negative is a credit (never below ₱0 for the bill). `line_id` optionally
     * names the line corrected. `reason` is required and shown to the payer.
     */
    public function adjust(Request $request, Bill $bill): JsonResponse
    {
        $this->authorizeProperty($request, $bill->property, A::EditIssuedBills);
        $data = $request->validate([
            'amount_centavos' => ['required', 'integer', 'between:-100000000,100000000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:255'],
            'line_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $bill = $this->charges->adjust($bill, $data['amount_centavos'], $data['description'] ?? null, $data['reason'], $data['line_id'] ?? null, $request->user());

        return ApiResponse::ok(new BillResource($bill->load(['lines', 'payer', 'room'])), 'Bill corrected.');
    }
}
