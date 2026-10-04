<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Enums\AuditEvent;
use App\Enums\BilledBy;
use App\Enums\PropertyAbility as A;
use App\Enums\UtilityMethod;
use App\Enums\UtilityType;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\PriceRuleResource;
use App\Http\Resources\Properties\UtilityAccountResource;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\UtilityAccount;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Pricing\PriceBook;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Utility accounts (D2, S4, SC-01, SC-31): any method for any utility.
 *
 * @group Utilities
 */
class UtilityAccountController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly AuditService $audit, private readonly PriceBook $prices) {}

    /**
     * List utility accounts
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        return ApiResponse::ok(UtilityAccountResource::collection($property->utilityAccounts()->get()));
    }

    /**
     * Add a utility account
     *
     * Fixed methods (per bedspace, per property, opt-in) need
     * `amount_centavos`. "Billed by: group" means the boarders handle it among
     * themselves and it never appears on the owner's bill.
     */
    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManagePrices);

        $data = $request->validate($this->rules($request, true));
        $method = UtilityMethod::from($data['method']);

        $account = DB::transaction(function () use ($property, $data, $method, $request) {
            $account = $property->utilityAccounts()->create([
                'type' => $data['type'],
                'name' => $data['name'] ?? UtilityType::from($data['type'])->label(),
                'method' => $method,
                'billed_by' => $data['billed_by'] ?? BilledBy::Owner->value,
                'notes' => $data['notes'] ?? null,
            ]);
            if ($method->hasFixedAmount()) {
                $this->prices->set($account, $data['amount_centavos'], null, $request->user());
            }

            return $account;
        });

        $this->audit->record(AuditEvent::UtilityAccountAdded, $account, owner: $property->owner,
            note: "{$property->name} · {$account->name} ({$method->label()})");

        return ApiResponse::created(new UtilityAccountResource($account));
    }

    /**
     * Change a utility account
     *
     * Switching to a fixed method needs `amount_centavos` (starts today).
     * To change only the amount later, use the amount endpoint.
     */
    public function update(Request $request, UtilityAccount $account): JsonResponse
    {
        $property = $account->property;
        $this->authorizeProperty($request, $property, A::ManagePrices);

        $data = $request->validate($this->rules($request, false));
        $before = $account->only(['type', 'name', 'method', 'billed_by', 'notes']);

        $newMethod = isset($data['method']) ? UtilityMethod::from($data['method']) : $account->method;
        if ($newMethod->hasFixedAmount() && ! isset($data['amount_centavos']) && $this->prices->amountOn($account) === null) {
            throw ValidationException::withMessages(['amount_centavos' => 'Enter the fixed amount for this method.']);
        }

        DB::transaction(function () use ($account, $data, $request) {
            $account->update(collect($data)->except('amount_centavos')->all());
            if ($account->method->hasFixedAmount() && isset($data['amount_centavos'])) {
                $this->prices->set($account, $data['amount_centavos'], null, $request->user());
            }
        });

        $changes = AuditDiff::between($before, $account->only(array_keys($before)));
        if ($changes !== []) {
            $this->audit->record(AuditEvent::UtilityAccountUpdated, $account, $changes, owner: $property->owner,
                note: "{$property->name} · {$account->name}");
        }

        return ApiResponse::ok(new UtilityAccountResource($account), 'Saved.');
    }

    /**
     * Change a fixed utility amount
     *
     * Effective-dated like rent: the current amount closes the day before.
     */
    public function setAmount(Request $request, UtilityAccount $account): JsonResponse
    {
        $property = $account->property;
        $this->authorizeProperty($request, $property, A::ManagePrices);

        if (! $account->method->hasFixedAmount()) {
            throw ValidationException::withMessages(['amount_centavos' => "{$account->method->label()} has no fixed amount."]);
        }

        $data = $request->validate([
            'amount_centavos' => ['required', 'integer', 'min:0', 'max:100000000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $before = $this->prices->amountOn($account);
        try {
            $rule = $this->prices->set($account, $data['amount_centavos'], $data['effective_from'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['effective_from' => $e->getMessage()]);
        }

        if (! $rule->wasRecentlyCreated) {
            return ApiResponse::ok(new UtilityAccountResource($account), 'That is already the amount. Nothing changed.');
        }

        $this->audit->record(AuditEvent::UtilityAmountChanged, $account,
            ['amount' => [$before === null ? null : Money::format($before), Money::format($rule->amount_centavos)]],
            owner: $property->owner,
            note: "{$property->name} · {$account->name} from ".$rule->effective_from->format('M j, Y'));

        return ApiResponse::ok(new UtilityAccountResource($account), 'Saved.');
    }

    /**
     * Amount history
     */
    public function priceHistory(Request $request, UtilityAccount $account): JsonResponse
    {
        $this->authorizeProperty($request, $account->property, A::View);

        return ApiResponse::ok(PriceRuleResource::collection($this->prices->history($account)));
    }

    /**
     * Remove a utility account
     *
     * Archived with its price history.
     */
    public function destroy(Request $request, UtilityAccount $account): JsonResponse
    {
        $property = $account->property;
        $this->authorizeProperty($request, $property, A::ManagePrices);

        $account->delete();
        $this->audit->record(AuditEvent::UtilityAccountRemoved, $account, owner: $property->owner,
            note: "{$property->name} · {$account->name}");

        return ApiResponse::message("{$account->name} removed.");
    }

    private function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $method = UtilityMethod::tryFrom((string) $request->input('method'));

        return [
            'type' => [$required, Rule::enum(UtilityType::class)],
            'name' => ['sometimes', 'nullable', 'string', 'max:80', Rule::requiredIf($request->input('type') === 'other')],
            'method' => [$required, Rule::enum(UtilityMethod::class)],
            'billed_by' => ['sometimes', Rule::enum(BilledBy::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount_centavos' => [
                Rule::requiredIf($creating && $method?->hasFixedAmount()),
                'nullable', 'integer', 'min:0', 'max:100000000',
            ],
        ];
    }
}
