<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ApplicationStatus;
use App\Enums\RentalMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\ApplicationResource;
use App\Http\Resources\Listings\ListingResource;
use App\Http\Responses\ApiResponse;
use App\Models\BookingApplication;
use App\Services\Listings\ListingSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dorm Finder (F1): public, no login.
 *
 * @group Dorm Finder
 */
class ListingController extends Controller
{
    public function __construct(private readonly ListingSearch $search) {}

    /**
     * Search listings
     *
     * Published properties of verified owners with at least one free unit.
     * Prices in centavos. `bbox` = south,west,north,east (map view).
     *
     * @unauthenticated
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'rental_mode' => ['nullable', Rule::enum(RentalMode::class)],
            'includes' => ['nullable', 'array'],
            'includes.*' => [Rule::in(ListingSearch::INCLUDABLE)],
            'who' => ['nullable', 'string', 'max:60'],
            'bbox' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'sort' => ['nullable', Rule::in(['newest', 'price'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! empty($data['bbox'])) {
            $data['bbox'] = array_map('floatval', explode(',', $data['bbox']));
        }

        $page = $this->search->search(array_filter($data, fn ($v) => $v !== null && $v !== ''));

        return ApiResponse::ok(
            collect($page->items())->map(fn ($p) => new ListingResource($p)),
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        );
    }

    /**
     * View a listing
     *
     * Signed-in boarders also get their open application here (and the street
     * address once their reservation is approved).
     *
     * @unauthenticated
     */
    public function show(Request $request, int $property): JsonResponse
    {
        $listing = $this->search->find($property);
        abort_unless($listing, 404, 'This listing is not available.');

        $viewer = auth('sanctum')->user();
        $mine = $viewer
            ? BookingApplication::where('boarder_id', $viewer->id)->where('property_id', $listing->id)->open()->latest('id')->first()
            : null;

        return ApiResponse::ok([
            'listing' => new ListingResource($listing, detail: true, showStreet: $mine?->status === ApplicationStatus::Approved),
            'my_application' => $mine ? new ApplicationResource($mine) : null,
        ]);
    }
}
