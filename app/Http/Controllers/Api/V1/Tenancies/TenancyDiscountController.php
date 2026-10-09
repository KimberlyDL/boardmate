<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Authorization\PropertyAccess;
use App\Enums\DiscountKind;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenancies\TenancyDiscountResource;
use App\Http\Responses\ApiResponse;
use App\Models\Tenancy;
use App\Services\Tenancies\TenancyDiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A tenancy's "Agreed rate" discount: manual, optional, effective-dated.
 *
 * @group Tenancy discounts
 */
class TenancyDiscountController extends Controller
{
    public function __construct(private readonly TenancyDiscountService $discounts) {}

    /**
     * Discount history
     *
     * Newest first, with each row's status (scheduled, active or ended). For
     * the property's staff and the tenant.
     */
    public function index(Request $request, Tenancy $tenancy): JsonResponse
    {
        $user = $request->user();
        abort_unless($tenancy->tenant_id === $user->id || PropertyAccess::can($user, A::View, $tenancy->property), 404);

        return ApiResponse::ok(TenancyDiscountResource::collection($tenancy->discounts()->with('setter:id,name')->get()));
    }

    /**
     * Set the discount
     *
     * Owner or Manager. `kind` is `fixed` (`value` in centavos) or `percent`
     * (`value` in basis points: 1000 = 10%). It starts on `effective_from`
     * (today if omitted; never in the past). The discount in force is closed
     * the day before, and a discount scheduled for later is replaced.
     * Billing applies the discount in force on the start of each rent period.
     */
    public function update(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeManage($request, $tenancy);

        $data = $request->validate([
            'kind' => ['required', Rule::enum(DiscountKind::class)],
            'value' => ['required', 'integer', 'min:1', 'max:100000000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        [$discount, $changed] = $this->discounts->set($tenancy, $data, $data['effective_from'] ?? null, $request->user());

        return ApiResponse::ok(new TenancyDiscountResource($discount), $changed ? 'Discount saved.' : 'That is already the discount. Nothing changed.');
    }

    /**
     * Take the discount away
     *
     * From `effective_from` (today if omitted). The discount in force is
     * closed the day before; scheduled ones are removed.
     */
    public function destroy(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeManage($request, $tenancy);

        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);
        $this->discounts->end($tenancy, $data['effective_from'] ?? null, $request->user());

        return ApiResponse::message('Discount ended.');
    }

    private function authorizeManage(Request $request, Tenancy $tenancy): void
    {
        $user = $request->user();
        abort_unless(PropertyAccess::can($user, A::View, $tenancy->property), 404);
        abort_unless(PropertyAccess::can($user, A::ManageTenancies, $tenancy->property), 403, 'You do not have permission to do this for this property.');
    }
}
