<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Enums\AuditEvent;
use App\Enums\PropertyAbility as A;
use App\Enums\RentalMode;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\PriceRuleResource;
use App\Http\Resources\Properties\UnitResource;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Pricing\PriceBook;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @group Units
 */
class UnitController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly AuditService $audit, private readonly PriceBook $prices) {}

    /**
     * List units
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        return ApiResponse::ok(UnitResource::collection(UnitResource::prepare($property->units()->get())));
    }

    /**
     * Rename or resize a unit
     */
    public function update(Request $request, RentableUnit $unit): JsonResponse
    {
        $property = $unit->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            // Max occupants of a whole room; a bedspace is always 1.
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        if ($unit->kind->value === 'bedspace') {
            unset($data['capacity']);
        }

        $before = $unit->only(array_keys($data));
        $unit->update($data);

        $changes = AuditDiff::between($before, $unit->only(array_keys($data)));
        if ($changes !== []) {
            $this->audit->record(AuditEvent::UnitUpdated, $unit, $changes, owner: $property->owner, note: "{$property->name} · {$unit->label}");
        }

        return ApiResponse::ok(new UnitResource($unit));
    }

    /**
     * Remove a bedspace
     *
     * Not while someone is booked into or living in it, and a bedspace room
     * always keeps at least one bedspace. The bedspace is archived with its
     * history.
     */
    public function destroy(Request $request, RentableUnit $unit): JsonResponse
    {
        $property = $unit->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $room = $unit->room;
        if ($room->rental_mode === RentalMode::Whole) {
            throw ValidationException::withMessages(['unit' => 'A room rented as a whole has exactly one unit. Switch the room to bedspaces instead.']);
        }
        DB::transaction(function () use ($property, $room, $unit) {
            $property->lockForOccupancyChange();
            if ($unit->refresh()->isInUse()) {
                throw ValidationException::withMessages(['unit' => 'Someone is booked into or living in this bedspace.']);
            }
            if ($room->units()->count() <= 1) {
                throw ValidationException::withMessages(['unit' => 'A bedspace room needs at least one bedspace.']);
            }

            $unit->delete();
        });
        $this->audit->record(AuditEvent::UnitRemoved, $unit, owner: $property->owner, note: "{$property->name} · {$unit->label}");

        return ApiResponse::message("{$unit->label} removed.");
    }

    /**
     * Mark a unit (not) ready
     *
     * Needs cleaning or repairs: hidden from bookings until cleared (S1). Does
     * not change anyone's tenancy.
     */
    public function notReady(Request $request, RentableUnit $unit): JsonResponse
    {
        $property = $unit->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $data = $request->validate([
            'not_ready' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if:not_ready,true', 'string', 'max:255'],
        ], ['reason.required_if' => 'Say what needs doing, e.g. "Repainting until Oct 10".']);

        $before = ['not_ready' => $unit->not_ready, 'not_ready_reason' => $unit->not_ready_reason];
        $unit->forceFill([
            'not_ready' => $data['not_ready'],
            'not_ready_reason' => $data['not_ready'] ? $data['reason'] : null,
        ])->save();

        $this->audit->record(AuditEvent::UnitNotReadyChanged, $unit,
            AuditDiff::between($before, ['not_ready' => $unit->not_ready, 'not_ready_reason' => $unit->not_ready_reason]),
            owner: $property->owner, note: "{$property->name} · {$unit->label}");

        return ApiResponse::ok(new UnitResource($unit));
    }

    /**
     * Set rent
     *
     * Effective-dated (S4): the current rent closes the day before
     * `effective_from` and the new one starts; past prices are never changed.
     * Omit `effective_from` to start today.
     */
    public function setRent(Request $request, RentableUnit $unit): JsonResponse
    {
        $property = $unit->property;
        $this->authorizeProperty($request, $property, A::ManagePrices);

        $data = $request->validate([
            'amount_centavos' => ['required', 'integer', 'min:0', 'max:100000000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $before = $this->prices->amountOn($unit);
        try {
            $rule = $this->prices->set($unit, $data['amount_centavos'], $data['effective_from'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['effective_from' => $e->getMessage()]);
        }

        if (! $rule->wasRecentlyCreated) {
            return ApiResponse::ok(new UnitResource($unit), 'That is already the rent. Nothing changed.');
        }

        $this->audit->record(AuditEvent::RentChanged, $unit,
            ['rent' => [$before === null ? null : Money::format($before), Money::format($rule->amount_centavos)]],
            owner: $property->owner,
            note: "{$property->name} · {$unit->label} from ".$rule->effective_from->format('M j, Y'));

        return ApiResponse::ok(new UnitResource($unit), 'Rent saved.');
    }

    /**
     * Rent history
     */
    public function priceHistory(Request $request, RentableUnit $unit): JsonResponse
    {
        $this->authorizeProperty($request, $unit->property, A::View);

        return ApiResponse::ok(PriceRuleResource::collection($this->prices->history($unit)));
    }
}
