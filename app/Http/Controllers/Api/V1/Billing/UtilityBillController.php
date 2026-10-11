<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\FilePurpose;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\UtilityBillResource;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\UtilityAccount;
use App\Models\UtilityBill;
use App\Services\Billing\MeteredBillService;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Metered utility bills (C3 SC-03): the provider's bill, entered when it
 * arrives; each room's share is billed to its leader.
 *
 * @group Billing
 */
class UtilityBillController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly MeteredBillService $metered) {}

    /**
     * List metered bills
     *
     * Latest entries first; corrected entries are left out (their
     * correction shows instead).
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        $bills = UtilityBill::where('property_id', $property->id)
            ->whereNotIn('id', UtilityBill::whereNotNull('replaces_id')->select('replaces_id'))
            ->with(['account', 'enteredBy:id,name'])
            ->latest('id')
            ->limit(100)
            ->get();

        return ApiResponse::ok(UtilityBillResource::collection($bills));
    }

    /**
     * Preview the split
     *
     * Each room's share, its leader and occupant-days, without saving.
     */
    public function preview(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageUtilityBills);
        $data = $request->validate($this->rules($property));

        $account = UtilityAccount::findOrFail($data['utility_account_id']);

        return ApiResponse::ok($this->metered->preview($account, $data['coverage_from_on'], $data['coverage_to_on'], $data['total_centavos'], $data['waive_room_ids'] ?? []));
    }

    /**
     * Enter a metered bill
     *
     * `utility_account_id` must be an "actual bill" utility the owner pays.
     * Each room's share is billed to its leader, due N days after today (the
     * property's utility due days, default 7). A room with no leader must be
     * waived (`waive_room_ids`) or get a leader first. Optional `photo` of the
     * bill (image or PDF, private).
     */
    public function store(Request $request, Property $property, FileService $files): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageUtilityBills);
        $data = $request->validate($this->rules($property) + [
            'photo' => ['nullable', ...array_slice($files->rules(FilePurpose::UtilityBillPhoto), 1)],
        ]);

        $account = UtilityAccount::findOrFail($data['utility_account_id']);
        $bill = $this->metered->enter($account, $data, $request->file('photo'), $request->user());

        return ApiResponse::created(new UtilityBillResource($bill->load(['account', 'enteredBy:id,name'])), 'Utility bill entered and billed.');
    }

    /**
     * Correct a metered bill's total
     *
     * Keeps the same split; each room's bill gets an adjustment line for the
     * difference, and its leader is told.
     */
    public function correct(Request $request, UtilityBill $utilityBill): JsonResponse
    {
        $this->authorizeProperty($request, $utilityBill->property, A::ManageUtilityBills);
        $data = $request->validate([
            'total_centavos' => ['required', 'integer', 'min:0', 'max:100000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $replacement = $this->metered->correct($utilityBill, $data['total_centavos'], $data['reason'], $request->user());

        return ApiResponse::ok(new UtilityBillResource($replacement->load(['account', 'enteredBy:id,name'])), 'Total corrected.');
    }

    private function rules(Property $property): array
    {
        return [
            'utility_account_id' => ['required', 'integer', Rule::exists('utility_accounts', 'id')->where('property_id', $property->id)->whereNull('deleted_at')],
            'coverage_from_on' => ['required', 'date_format:Y-m-d'],
            'coverage_to_on' => ['required', 'date_format:Y-m-d'],
            'total_centavos' => ['required', 'integer', 'min:1', 'max:100000000'],
            'waive_room_ids' => ['sometimes', 'array'],
            'waive_room_ids.*' => ['integer'],
        ];
    }
}
