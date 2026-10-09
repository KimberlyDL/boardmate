<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Authorization\PropertyAccess;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancies\MoveInRequest;
use App\Http\Requests\Tenancies\WalkInRequest;
use App\Http\Resources\Tenancies\TenancyResource;
use App\Http\Responses\ApiResponse;
use App\Models\BookingApplication;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use App\Services\Tenancies\TenancyService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tenancies: a stay from move-in. Staff work through a property
 * (`manage_tenancies` to move in or change, `view` to read); a tenant reads
 * their own.
 *
 * @group Tenancies
 */
class TenancyController extends Controller
{
    use AuthorizesProperty;

    private const WITH = ['tenant', 'room.property.building', 'unit', 'property', 'discounts'];

    public function __construct(private readonly TenancyService $tenancies) {}

    /**
     * List a property's tenancies
     *
     * Current tenancies by default; `room_id` narrows to one room. Includes
     * the move-in payments and emergency contacts (staff only).
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);
        $data = $request->validate(['room_id' => ['nullable', 'integer']]);

        $page = $property->tenancies()->current()
            ->with([...self::WITH, 'payments'])
            ->when($data['room_id'] ?? null, fn ($q, $id) => $q->where('room_id', $id))
            ->orderByDesc('moved_in_on')->orderByDesc('id')
            ->paginate(50);

        app(PriceBook::class)->preload($page->getCollection()->pluck('unit'));

        return ApiResponse::ok(
            $page->getCollection()->map(fn (Tenancy $t) => new TenancyResource($t)),
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        );
    }

    /**
     * View a tenancy
     *
     * Staff of the property see everything. The tenant sees their own
     * tenancy without the move-in payments' bookkeeping.
     */
    public function show(Request $request, Tenancy $tenancy): JsonResponse
    {
        $tenancy->load([...self::WITH, 'payments']);

        if (PropertyAccess::can($request->user(), A::View, $tenancy->property)) {
            return ApiResponse::ok(new TenancyResource($tenancy));
        }
        abort_unless($tenancy->tenant_id === $request->user()->id, 404);

        return ApiResponse::ok(new TenancyResource($tenancy, 'tenant'));
    }

    /**
     * My tenancies
     *
     * The signed-in boarder's current stay (a list, normally of one).
     */
    public function mine(Request $request): JsonResponse
    {
        $tenancies = Tenancy::current()->where('tenant_id', $request->user()->id)->with(self::WITH)->get();
        app(PriceBook::class)->preload($tenancies->pluck('unit'));

        return ApiResponse::ok($tenancies->map(fn (Tenancy $t) => new TenancyResource($t, 'tenant')));
    }

    /**
     * Preview a move-in
     *
     * What the rent, deposit and first rent would be for a unit on a day,
     * with an optional discount (`discount_kind`: fixed or percent, and
     * `discount_value` in centavos or basis points). The first rent is the
     * rent after the discount, with no utilities.
     */
    public function preview(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageTenancies);
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'moved_in_on' => ['sometimes', 'date_format:Y-m-d'],
            'discount_kind' => ['required_with:discount_value', 'nullable', Rule::in(['fixed', 'percent'])],
            'discount_value' => ['required_with:discount_kind', 'nullable', 'integer', 'min:1', 'max:100000000'],
        ]);

        $unit = RentableUnit::where('property_id', $property->id)->find($data['unit_id']);
        if (! $unit) {
            throw ValidationException::withMessages(['unit_id' => 'Choose a unit of this property.']);
        }
        $discount = isset($data['discount_kind'], $data['discount_value'])
            ? ['kind' => $data['discount_kind'], 'value' => (int) $data['discount_value']]
            : null;

        return ApiResponse::ok($this->tenancies->quote(
            $unit, ManilaDate::parse($data['moved_in_on'] ?? ManilaDate::today()->toDateString())->startOfDay(), $discount, $property->settings,
        ));
    }

    /**
     * Move in from a reservation
     *
     * The reservation becomes a tenancy and its unit Occupied. With the
     * property's activation requirement on, the deposit and the first rent
     * must be recorded in full, or an owner or Manager gives an
     * `override_reason`. In a room rented whole the tenant becomes the leader
     * (`leader_consent: true` is needed) and the people to stay need
     * emergency contacts; in a bedspace room `make_leader` names the tenant
     * the leader when the room has none.
     */
    public function moveIn(MoveInRequest $request, BookingApplication $application): JsonResponse
    {
        $tenancy = $this->tenancies->moveInFromReservation($application, $request->moveInData(), $request->user());

        return ApiResponse::created($this->one($tenancy), "{$tenancy->tenant->name} is moved in.");
    }

    /**
     * Move in a walk-in
     *
     * For someone who never applied: pick an available `unit_id` and the
     * `email` of their BoardMate account. Everything else is as for a
     * reservation.
     */
    public function store(WalkInRequest $request, Property $property): JsonResponse
    {
        $tenant = User::where('email', mb_strtolower($request->validated('email')))->first();
        if (! $tenant) {
            throw ValidationException::withMessages(['email' => 'No BoardMate account uses this email. Ask the person to sign up first.']);
        }

        $tenancy = $this->tenancies->moveInWalkIn($property, $tenant, (int) $request->validated('unit_id'), $request->moveInData(), $request->user());

        return ApiResponse::created($this->one($tenancy), "{$tenancy->tenant->name} is moved in.");
    }

    /**
     * Change the emergency contact
     *
     * The tenant or the property's owner or Manager.
     */
    public function updateEmergencyContact(Request $request, Tenancy $tenancy): JsonResponse
    {
        $isTenant = $tenancy->tenant_id === $request->user()->id;
        abort_unless($isTenant || PropertyAccess::can($request->user(), A::View, $tenancy->property), 404);
        abort_unless($isTenant || PropertyAccess::can($request->user(), A::ManageTenancies, $tenancy->property), 403, 'You do not have permission to do this for this property.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
        ]);

        $this->tenancies->updateEmergencyContact($tenancy, $data, $request->user());

        return ApiResponse::ok($this->one($tenancy), 'Emergency contact saved.');
    }

    private function one(Tenancy $tenancy): TenancyResource
    {
        return new TenancyResource($tenancy->load([...self::WITH, 'payments']));
    }
}
