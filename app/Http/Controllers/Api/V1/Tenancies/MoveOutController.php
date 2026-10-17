<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenancy;
use App\Services\Tenancies\MoveOutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Tenancies
 */
class MoveOutController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly MoveOutService $moveOuts) {}

    /**
     * Record a move-out
     *
     * The day the boarder actually left (`moved_out_on`, today or up to 30
     * days back). Owner or any caretaker. Without an end date yet (left
     * without notice), `reason` is required. The tenancy ends, the unit is
     * available again, a whole room's occupants leave, and the last month's
     * fixed utilities go on a final bill. The deposit is settled next.
     */
    public function store(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ApproveMoveOut);
        $data = $request->validate([
            'moved_out_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $tenancy = $this->moveOuts->recordMoveOut($tenancy, $data['moved_out_on'], $data['reason'] ?? null, $request->user());
        $final = $tenancy->bills()->where('kind', 'final')->first();

        return ApiResponse::ok([
            'id' => $tenancy->id,
            'status' => $tenancy->status->value,
            'status_label' => $tenancy->status->label(),
            'end_on' => $tenancy->end_on?->toDateString(),
            'moved_out_on' => $tenancy->moved_out_on->toDateString(),
            'unit_status' => $tenancy->unit->fresh()->status->value,
            'final_bill' => $final ? ['id' => $final->id, 'number' => $final->number, 'total_centavos' => $final->totalCentavos()] : null,
        ], 'Move-out recorded. Settle the deposit next.');
    }
}
