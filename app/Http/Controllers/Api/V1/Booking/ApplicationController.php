<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Enums\ApplicationStatus;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\ApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\BookingApplication;
use App\Models\RoomLeader;
use App\Services\Booking\BookingService;
use App\Services\Pricing\PriceBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reviewing applications: the owner and the property's Managers
 * (`approve_bookings`). Collectors do not see applications.
 *
 * @group Bookings
 */
class ApplicationController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly BookingService $bookings) {}

    /**
     * List applications
     *
     * For every property you can approve bookings on. Default: pending,
     * oldest first. `meta.pending_count` counts all pending ones.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'property_id' => ['nullable', 'integer'],
        ]);
        $status = ApplicationStatus::tryFrom($data['status'] ?? '') ?? ApplicationStatus::Pending;

        $base = BookingApplication::query()->reviewableBy($request->user());
        $page = (clone $base)
            ->with(['property.owner.ownerProfile', 'unit', 'boarder', 'decider'])
            ->where('status', $status)
            ->when($data['property_id'] ?? null, fn ($q, $id) => $q->where('property_id', $id))
            ->orderBy('id', $status === ApplicationStatus::Pending ? 'asc' : 'desc')
            ->paginate(25);

        ApplicationResource::prepare($page->items(), 'reviewer');

        return ApiResponse::ok(
            collect($page->items())->map(fn ($a) => new ApplicationResource($a, 'reviewer')),
            meta: [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'pending_count' => (clone $base)->where('status', ApplicationStatus::Pending)->count(),
            ],
        );
    }

    /**
     * View an application
     *
     * Includes the bookable units to choose from when approving.
     */
    public function show(Request $request, BookingApplication $application): JsonResponse
    {
        $this->authorizeProperty($request, $application->property, A::ApproveBookings);

        $prices = app(PriceBook::class);
        $bookable = $application->property->bookableUnits()->get();
        $prices->preload($bookable);
        $property = $application->property->loadMissing('building');
        $rooms = $property->rooms()->get()->each(fn ($r) => $r->setRelation('property', $property))->keyBy('id');
        $withLeader = RoomLeader::current()->whereIn('room_id', $bookable->pluck('room_id'))->pluck('room_id')->flip();
        $units = $bookable->map(fn ($u) => [
            'id' => $u->id,
            'room_code' => $rooms->get($u->room_id)?->code(),
            'label' => $u->label,
            'kind' => $u->kind->value,
            'capacity' => $u->capacity,
            'room_has_leader' => $withLeader->has($u->room_id),
            'rent_centavos' => $prices->amountOn($u),
        ]);

        return ApiResponse::ok(new ApplicationResource($application, 'reviewer'), meta: ['bookable_units' => $units]);
    }

    /**
     * Approve (reserve a unit)
     *
     * `unit_id` is the bedspace or whole room. For a room rented whole the
     * applicant leads it and `occupants` lists the other people who will stay
     * (name required; contacts can be completed at move-in), within the
     * unit's capacity. For a bedspace, `leader: true` names the applicant the
     * room's leader when it has none yet.
     */
    public function approve(Request $request, BookingApplication $application): JsonResponse
    {
        $this->authorizeProperty($request, $application->property, A::ApproveBookings);
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'leader' => ['sometimes', 'boolean'],
            'occupants' => ['sometimes', 'array', 'max:49'],
            'occupants.*.name' => ['required', 'string', 'max:120'],
            'occupants.*.contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'occupants.*.emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'occupants.*.emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
            'occupants.*.emergency_contact_phone' => ['nullable', 'required_with:occupants.*.emergency_contact_name', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
        ]);

        $application = $this->bookings->approve(
            $application, $data['unit_id'], $request->user(), (bool) ($data['leader'] ?? false), $data['occupants'] ?? [],
        );

        return ApiResponse::ok(new ApplicationResource($application->load(['unit', 'boarder']), 'reviewer'),
            "{$application->unit->label} is reserved for {$application->boarder->name} until ".$application->reserved_until->format('M j').'.');
    }

    /**
     * Decline an application
     */
    public function decline(Request $request, BookingApplication $application): JsonResponse
    {
        $this->authorizeProperty($request, $application->property, A::ApproveBookings);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->bookings->decline($application, $data['reason'] ?? null, $request->user());

        return ApiResponse::ok(new ApplicationResource($application->fresh(), 'reviewer'), 'Application declined.');
    }

    /**
     * Cancel a reservation
     *
     * The unit becomes available again; the boarder is told why.
     */
    public function cancel(Request $request, BookingApplication $application): JsonResponse
    {
        $this->authorizeProperty($request, $application->property, A::ApproveBookings);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->bookings->cancel($application, $request->user(), $data['reason']);

        return ApiResponse::ok(new ApplicationResource($application->fresh(), 'reviewer'), 'Reservation cancelled.');
    }
}
