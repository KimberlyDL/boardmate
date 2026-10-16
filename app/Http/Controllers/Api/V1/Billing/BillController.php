<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\BillKind;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\BillResource;
use App\Http\Responses\ApiResponse;
use App\Models\Bill;
use App\Models\Property;
use App\Models\Tenancy;
use App\Services\Billing\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bills issued at a property, for the owner and caretakers.
 *
 * @group Billing
 */
class BillController extends Controller
{
    use AuthorizesProperty;

    /**
     * List a property's bills
     *
     * `status`: `unpaid` (default; oldest due first, partly paid included),
     * `paid`, or `all` (newest first). Optional `payer_id` and `kind`. Each
     * bill has its lines, `balance_centavos` (still owed) and `is_overdue`.
     * `meta.unpaid_total_centavos` is everything still owed at the property.
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::RecordPayments);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['unpaid', 'paid', 'all'])],
            'payer_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::enum(BillKind::class)],
        ]);
        $status = $data['status'] ?? 'unpaid';

        $base = Bill::where('property_id', $property->id);
        $page = (clone $base)
            ->withBalance()
            ->with(['lines', 'payer:id,name', 'room'])
            ->when($status === 'unpaid', fn ($q) => $q->unpaid()->orderBy('due_on')->orderBy('id'))
            ->when($status === 'paid', fn ($q) => $q->paid()->latest('due_on')->latest('id'))
            ->when($status === 'all', fn ($q) => $q->latest('due_on')->latest('id'))
            ->when($data['payer_id'] ?? null, fn ($q, $id) => $q->where('payer_id', $id))
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->paginate(25);

        $unpaid = (clone $base)->unpaid()->withBalance()->get();

        return ApiResponse::ok(BillResource::collection($page->items()), meta: [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'unpaid_count' => $unpaid->count(),
            'unpaid_total_centavos' => (int) $unpaid->sum('balance_centavos'),
        ]);
    }

    /**
     * My bills
     *
     * The signed-in boarder's bills everywhere they stay or stayed. `status`:
     * `unpaid` (default, oldest due first), `paid` or `all` (newest first).
     * `meta.places` lists each place with what they owe, their credit, and
     * how to pay the owner (GCash or bank, when the owner gave them).
     */
    public function mine(Request $request, PaymentLedger $ledger): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['unpaid', 'paid', 'all'])]]);
        $status = $data['status'] ?? 'unpaid';
        $user = $request->user();

        $page = Bill::where('payer_id', $user->id)
            ->withBalance()
            ->with(['lines', 'payer:id,name', 'room', 'property:id,name'])
            ->when($status === 'unpaid', fn ($q) => $q->unpaid()->orderBy('due_on')->orderBy('id'))
            ->when($status === 'paid', fn ($q) => $q->paid()->latest('due_on')->latest('id'))
            ->when($status === 'all', fn ($q) => $q->latest('due_on')->latest('id'))
            ->paginate(25);

        $places = Property::withTrashed()
            ->whereIn('id', Tenancy::where('tenant_id', $user->id)->select('property_id'))
            ->with('owner.ownerProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (Property $p) => [
                'property_id' => $p->id,
                'property_name' => $p->name,
                ...$ledger->summary($p->id, $user->id),
                'pay_to' => $this->payTo($p),
            ])
            ->values();

        return ApiResponse::ok(BillResource::collection($page->items()), meta: [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'places' => $places,
        ]);
    }

    /** @return array{gcash_name: ?string, gcash_number: ?string, bank_name: ?string, bank_account_name: ?string, bank_account_number: ?string}|null */
    private function payTo(Property $property): ?array
    {
        $profile = $property->owner?->ownerProfile;
        if (! $profile || (blank($profile->gcash_number) && blank($profile->bank_account_number))) {
            return null;
        }

        return $profile->only(['gcash_name', 'gcash_number', 'bank_name', 'bank_account_name', 'bank_account_number']);
    }
}
