<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Authorization\PropertyAccess;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\HouseRuleVersionResource;
use App\Http\Responses\ApiResponse;
use App\Models\HouseRuleAcceptance;
use App\Models\Tenancy;
use App\Services\Properties\HouseRuleAcceptances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * House rules for a stay (D3): the rules in force, and which versions the
 * tenant accepted, when and how (the record to show in a dispute).
 *
 * @group House rules
 */
class HouseRuleAcceptanceController extends Controller
{
    public function __construct(private readonly HouseRuleAcceptances $acceptances) {}

    /**
     * Get a stay's house rules
     *
     * For the tenant, or anyone who can see the property. `needs_acceptance`:
     * the tenant has not accepted the version in force yet.
     */
    public function show(Request $request, Tenancy $tenancy): JsonResponse
    {
        if ($tenancy->tenant_id !== $request->user()->id) {
            abort_unless(PropertyAccess::can($request->user(), A::View, $tenancy->property), 404);
        }
        $current = $this->acceptances->current($tenancy->property)?->load('rules');
        $history = $tenancy->houseRuleAcceptances()->with(['version:id,version', 'recorder:id,name'])->orderByDesc('accepted_at')->get();

        return ApiResponse::ok([
            'current' => $current ? new HouseRuleVersionResource($current) : null,
            'needs_acceptance' => $tenancy->status->isCurrent() && ! $this->acceptances->isUpToDate($tenancy, $current),
            'acceptances' => $history->map(fn (HouseRuleAcceptance $a) => [
                'version' => $a->version->version,
                'method' => $a->method->value,
                'method_label' => $a->method->label(),
                'recorded_by' => $a->recorder?->name,
                'accepted_at' => $a->accepted_at->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Accept the house rules
     *
     * The tenant accepts the version in force, sent as `version` so an older
     * screen cannot accept newer rules unseen.
     */
    public function accept(Request $request, Tenancy $tenancy): JsonResponse
    {
        abort_unless($tenancy->tenant_id === $request->user()->id, 404);
        abort_unless($tenancy->status->isCurrent(), 403, 'This stay has ended.');
        $data = $request->validate(['version' => ['required', 'integer']]);

        $this->acceptances->acknowledge($tenancy, (int) $data['version']);

        return ApiResponse::ok(null, 'Thanks. You accepted the house rules.');
    }
}
