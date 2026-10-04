<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Enums\ApplicationStatus;
use App\Enums\FilePurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\ApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\BookingApplication;
use App\Services\Booking\BookingService;
use App\Services\Files\Contracts\FileService;
use App\Services\Listings\ListingSearch;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The boarder's own applications and reservation.
 *
 * @group Bookings
 */
class MyApplicationController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * Apply for a listing
     *
     * For bedspace properties the owner picks the bedspace when approving.
     * Optional ID photo (`id_document`: jpg/png/webp/pdf, up to 5 MB) is seen
     * only by the owner and the property's Managers, and deleted 30 days after
     * the application closes.
     */
    public function store(Request $request, int $property, ListingSearch $search, FileService $files): JsonResponse
    {
        $listing = $search->find($property);
        abort_unless($listing, 404, 'This listing is not available.');

        $data = $request->validate([
            'planned_move_in_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.ManilaDate::today()->toDateString(),
                'before_or_equal:'.ManilaDate::today()->addMonths(6)->toDateString()],
            'message' => ['nullable', 'string', 'max:1000'],
            'contact_phone' => ['required', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'id_document' => ['nullable', ...array_slice($files->rules(FilePurpose::IdDocument), 1)],
        ], [
            'planned_move_in_on.after_or_equal' => 'Choose today or a later date.',
            'planned_move_in_on.before_or_equal' => 'Choose a date within the next 6 months.',
            'contact_phone.regex' => 'Enter a valid phone number.',
        ]);

        $application = $this->bookings->apply($request->user(), $listing, $data, $request->file('id_document'));

        return ApiResponse::created(new ApplicationResource($application->load('property')),
            'Application sent. We will let you know when the owner replies.');
    }

    /**
     * My applications
     *
     * Newest first; the active reservation (if any) is in `meta.reservation_id`.
     */
    public function index(Request $request): JsonResponse
    {
        $applications = BookingApplication::with(['property.owner.ownerProfile', 'unit'])
            ->where('boarder_id', $request->user()->id)
            ->latest('id')
            ->limit(50)
            ->get();

        return ApiResponse::ok(
            $applications->map(fn ($a) => new ApplicationResource($a)),
            meta: ['reservation_id' => $applications->first(fn ($a) => $a->status === ApplicationStatus::Approved)?->id],
        );
    }

    /**
     * Cancel my application or reservation
     */
    public function cancel(Request $request, BookingApplication $application): JsonResponse
    {
        abort_unless($application->boarder_id === $request->user()->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->bookings->cancel($application, $request->user(), $data['reason'] ?? null);

        return ApiResponse::ok(new ApplicationResource($application->fresh()), 'Cancelled.');
    }
}
