<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Enums\AuditEvent;
use App\Enums\PropertyAbility as A;
use App\Enums\RentalMode;
use App\Enums\WhoCanStay;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\RoomResource;
use App\Http\Resources\Properties\UnitResource;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\Room;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Properties\PropertySetup;
use App\Support\Money;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * @group Rooms
 */
class RoomController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly AuditService $audit, private readonly PropertySetup $setup) {}

    /**
     * List rooms
     *
     * By floor (rooms without a floor first), then room number. Each room
     * carries its code, rental mode and units.
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        return ApiResponse::ok(RoomResource::collection($this->prepared($property)));
    }

    /**
     * Add a room
     *
     * The room is rented whole (`rental_mode: whole`, one unit with a max
     * `capacity`) or by bedspace (`rental_mode: bedspaces`, `count` beds). The
     * floor, door number (`door_no`, unique within the property) and
     * `who_can_stay` are optional; the room number and code are automatic.
     */
    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $data = $request->validate([
            'rental_mode' => ['required', Rule::enum(RentalMode::class)],
            ...$this->detailRules($property),
            ...$this->unitSpecRules(),
        ]);

        $details = collect($data)->only(['floor', 'door_no', 'who_can_stay'])->all();
        $room = $this->setup->createRoom($property, RentalMode::from($data['rental_mode']), $data['units'] ?? [], $request->user(), $details);

        $this->audit->record(AuditEvent::RoomAdded, $room, owner: $property->owner, note: "{$property->name} · {$room->code()}");

        return ApiResponse::created($this->one($room), "Room {$room->label()} added.");
    }

    /**
     * Change a room's floor, door number or who can stay
     *
     * Send only the fields that change.
     */
    public function update(Request $request, Room $room): JsonResponse
    {
        $property = $room->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $data = $request->validate($this->detailRules($property, $room));

        $fields = ['floor', 'door_no', 'who_can_stay'];
        $before = $room->only($fields);
        $codeBefore = $room->code();
        $room->update($data);

        $changes = AuditDiff::between($before, $room->only($fields));
        if ($changes !== []) {
            $this->audit->record(AuditEvent::RoomUpdated, $room, $changes, owner: $property->owner, note: "{$property->name} · {$codeBefore} → {$room->code()}");
        }

        return ApiResponse::ok($this->one($room));
    }

    /**
     * Remove a room
     *
     * Not while someone is booked into or living in one of its units, and a
     * property always keeps at least one room. The room and its units are
     * archived with their history.
     */
    public function destroy(Request $request, Room $room): JsonResponse
    {
        $property = $room->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $code = $room->label();
        $blocks = $this->setup->deleteRoomIfFree($room);
        if ($blocks->isNotEmpty()) {
            return response()->json([
                'message' => "Someone is booked into or living in room {$code} (".$blocks->pluck('label')->implode(', ').'). It can be removed once all its units are free.',
                'code' => 'room_in_use',
            ], 409);
        }

        $this->audit->record(AuditEvent::RoomRemoved, $room, owner: $property->owner, note: "{$property->name} · {$code}");

        return ApiResponse::message("Room {$code} removed.");
    }

    /**
     * Switch a room's rental mode
     *
     * Whole ⇄ bedspaces. Refused while any unit of the room is in use. A
     * person joining or leaving a room never switches it. The old units are
     * archived with their price history.
     */
    public function switchMode(Request $request, Room $room): JsonResponse
    {
        $property = $room->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $data = $request->validate([
            'rental_mode' => ['required', Rule::enum(RentalMode::class)],
            ...$this->unitSpecRules(),
        ]);

        $before = $room->rental_mode;
        $this->setup->switchMode($room, RentalMode::from($data['rental_mode']), $data['units'] ?? [], $request->user());

        $this->audit->record(AuditEvent::RentalModeSwitched, $room,
            ['rental_mode' => [$before, $room->rental_mode]], owner: $property->owner, note: "{$property->name} · {$room->code()}");

        return ApiResponse::ok($this->one($room->refresh()), 'Rental mode changed.');
    }

    /**
     * Add bedspaces
     *
     * Bedspace rooms only. `label_pattern` uses {n} for the number, e.g.
     * "Bed {n}". Numbering continues after existing bedspaces unless
     * `start_number` is given.
     */
    public function addBedspaces(Request $request, Room $room): JsonResponse
    {
        $property = $room->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.PropertySetup::MAX_BEDSPACES],
            'label_pattern' => ['sometimes', 'string', 'max:60'],
            'start_number' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'rent_centavos' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $units = $this->setup->addBedspaces(
            $room, $data['count'], $data['label_pattern'] ?? 'Bed {n}', $data['start_number'] ?? null,
            $data['rent_centavos'] ?? null, $request->user(),
        );

        $this->audit->record(AuditEvent::UnitsAdded, $property, owner: $property->owner,
            note: "{$room->code()}: ".$units->count().' bedspace(s): '.$units->pluck('label')->implode(', '));

        return ApiResponse::created(UnitResource::collection(UnitResource::prepare($units)), $units->count().' bedspace(s) added.');
    }

    /**
     * Also rent a bedspace room as a whole
     *
     * Adds (or re-prices) the room's whole-room option: `rent_centavos` per
     * month, for up to `capacity` people (default one per bedspace). It is
     * offered only while every bedspace is free, and while someone holds it
     * the bedspaces are not offered. Remove it like any unit.
     */
    public function offerWhole(Request $request, Room $room): JsonResponse
    {
        $property = $room->property;
        $this->authorizeProperty($request, $property, A::ManageUnits);
        $this->authorizeProperty($request, $property, A::ManagePrices);

        $data = $request->validate([
            'rent_centavos' => ['required', 'integer', 'min:1', 'max:100000000'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $had = $room->wholeOption()->exists();
        $option = $this->setup->offerWhole($room, $data['rent_centavos'], $data['capacity'] ?? null, $request->user());

        $this->audit->record($had ? AuditEvent::UnitUpdated : AuditEvent::UnitsAdded, $option, owner: $property->owner,
            note: "{$property->name} · {$room->code()}: whole-room option, ".Money::format($data['rent_centavos']).", up to {$option->capacity}");

        return ApiResponse::ok($this->one($room->refresh()), "Room {$room->label()} can also be rented whole.");
    }

    /** @return Collection<int, Room> */
    private function prepared(Property $property): Collection
    {
        $units = UnitResource::prepare($property->units()->get())->groupBy('room_id');

        return $property->rooms()->with('leader.user')->withCount(['occupants' => fn ($q) => $q->whereNull('left_on')])->get()->each(function (Room $room) use ($property, $units) {
            $room->setRelation('property', $property);
            $room->setRelation('units', $units->get($room->id, collect())->values());
        });
    }

    private function one(Room $room): RoomResource
    {
        $room->load('leader.user')->loadCount(['occupants' => fn ($q) => $q->whereNull('left_on')]);
        $room->setRelation('units', UnitResource::prepare($room->units()->get()));

        return new RoomResource($room);
    }

    /** Floor, door number and who can stay; the door number is unique within the property, ignoring case. */
    private function detailRules(Property $property, ?Room $room = null): array
    {
        return [
            'floor' => ['sometimes', 'nullable', 'integer', 'between:-5,100'],
            'door_no' => ['sometimes', 'nullable', 'string', 'max:10',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $room) {
                    $taken = Room::where('property_id', $property->id)
                        ->whereRaw('lower(door_no) = ?', [mb_strtolower((string) $value)])
                        ->when($room, fn ($q) => $q->whereKeyNot($room->id))
                        ->exists();
                    if ($taken) {
                        $fail("Another room in this property already uses door {$value}.");
                    }
                }],
            'who_can_stay' => ['sometimes', Rule::enum(WhoCanStay::class)],
        ];
    }

    private function unitSpecRules(): array
    {
        return [
            'units' => ['sometimes', 'array'],
            'units.capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'units.rent_centavos' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'units.count' => ['sometimes', 'integer', 'min:1', 'max:'.PropertySetup::MAX_BEDSPACES],
            'units.label_pattern' => ['sometimes', 'string', 'max:60'],
            'units.start_number' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
