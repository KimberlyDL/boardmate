<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Enums\ApplicationStatus;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\ApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\BookingApplication;
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
        $units = $bookable->map(fn ($u) => [
            'id' => $u->id,
            'label' => $u->label,
            'rent_centavos' => $prices->amountOn($u),
        ]);

        return ApiResponse::ok(new ApplicationResource($application, 'reviewer'), meta: ['bookable_units' => $units]);
    }

    /**
     * Approve (reserve a unit)
     */
    public function approve(Request $request, BookingApplication $application): JsonResponse
    {
        $this->authorizeProperty($request, $application->property, A::ApproveBookings);
        $data = $request->validate(['unit_id' => ['required', 'integer']]);

        $application = $this->bookings->approve($application, $data['unit_id'], $request->user());

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
