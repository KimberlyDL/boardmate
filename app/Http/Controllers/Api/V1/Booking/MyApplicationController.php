<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Enums\ApplicationStatus;
use App\Enums\FilePurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\ApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\BookingApplication;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\Room;
use App\Services\Booking\BookingService;
use App\Services\Files\Contracts\FileService;
use App\Services\Listings\ListingSearch;
use App\Services\Properties\HouseRuleAcceptances;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The boarder's own applications and reservation.
 *
 * @group Bookings
 */
class MyApplicationController extends Controller
{
    public function __construct(private readonly BookingService $bookings, private readonly HouseRuleAcceptances $houseRules) {}

    /**
     * Apply for a listing
     *
     * `room_id` is the room the boarder wants (required when the place has
     * more than one room) and must have a free place; `whole: true` asks for
     * the whole room where the owner offers it. The owner picks the
     * bedspace when approving and may place the boarder in another room.
     * Optional ID photo (`id_document`: jpg/png/webp/pdf, up to 5 MB) is seen
     * only by the owner and the property's Managers, and deleted 30 days after
     * the application closes.
     */
    public function store(Request $request, int $property, ListingSearch $search, FileService $files): JsonResponse
    {
        abort_unless($search->find($property), 404, 'This listing is not available.');
        $listing = $search->find($property, bookable: true);
        if (! $listing) {
            throw ValidationException::withMessages(['property' => 'This place has no free room right now.']);
        }

        $data = $request->validate([
            'planned_move_in_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.ManilaDate::today()->toDateString(),
                'before_or_equal:'.ManilaDate::today()->addMonths(6)->toDateString()],
            'message' => ['nullable', 'string', 'max:1000'],
            'contact_phone' => ['required', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'room_id' => ['nullable', 'integer'],
            'whole' => ['sometimes', 'boolean'],
            'id_document' => ['nullable', ...array_slice($files->rules(FilePurpose::IdDocument), 1)],
            'house_rules_version' => ['nullable', 'integer'],
        ], [
            'planned_move_in_on.after_or_equal' => 'Choose today or a later date.',
            'planned_move_in_on.before_or_equal' => 'Choose a date within the next 6 months.',
            'contact_phone.regex' => 'Enter a valid phone number.',
        ]);

        $rules = $this->houseRules->requireForApplication($listing, isset($data['house_rules_version']) ? (int) $data['house_rules_version'] : null);
        $data['wants_whole'] = (bool) ($data['whole'] ?? false);
        unset($data['whole'], $data['house_rules_version']);
        $data['room_id'] = $this->chosenRoom($listing, $data['room_id'] ?? null, $data['wants_whole'])->id;

        $application = $this->bookings->apply($request->user(), $listing, $data, $request->file('id_document'));
        if ($rules) {
            $this->houseRules->recordForApplication($rules, $application);
        }

        return ApiResponse::created(new ApplicationResource($application->load('property')),
            'Application sent. We will let you know when the owner replies.');
    }

    /**
     * The room applied for: the one chosen, or the only room of a one-room
     * place. It must have a free place now, or, for `$whole`, its whole-room
     * option must be free.
     */
    private function chosenRoom(Property $listing, ?int $roomId, bool $whole = false): Room
    {
        $rooms = $listing->rooms;
        $room = $roomId ? $rooms->firstWhere('id', $roomId) : ($rooms->count() === 1 ? $rooms->first() : null);
        if (! $room) {
            throw ValidationException::withMessages(['room_id' => $roomId ? 'Choose a room of this place.' : 'Choose the room you want.']);
        }
        if ($whole) {
            if (! $listing->units->contains(fn (RentableUnit $u) => $u->room_id === $room->id && $u->is_whole_option && $u->isAvailable())) {
                throw ValidationException::withMessages(['whole' => "Room {$room->label()} cannot be rented as a whole right now."]);
            }

            return $room;
        }
        $free = $listing->units->contains(fn (RentableUnit $u) => $u->room_id === $room->id && ! $u->is_whole_option && $u->isAvailable());
        if (! $free) {
            throw ValidationException::withMessages(['room_id' => "Room {$room->label()} has no free place right now. Choose another room."]);
        }

        return $room;
    }

    /**
     * My applications
     *
     * Newest first; the active reservation (if any) is in `meta.reservation_id`.
     */
    public function index(Request $request): JsonResponse
    {
        $applications = BookingApplication::with(['property.owner.ownerProfile', 'unit', 'room'])
            ->where('boarder_id', $request->user()->id)
            ->latest('id')
            ->limit(50)
            ->get();

        ApplicationResource::prepare($applications);

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
