<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\PropertyAccess;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Services\Documents\Contracts\DocumentService;
use App\Services\Finance\FinanceOverview;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Finances for the owner and Managers (`view_collections`): billed,
 * collected, outstanding, deposits, utilities and repair costs for a period,
 * across properties, per room or per stay. Amounts are in centavos; see
 * FinanceFigures for what each figure counts. Each view has a PDF statement
 * behind a short-lived link, like the other documents.
 *
 * @group Finances
 */
class FinanceController extends Controller
{
    use AuthorizesProperty;

    public function __construct(
        private readonly FinanceOverview $overview,
        private readonly DocumentService $documents,
    ) {}

    /**
     * My finances
     *
     * `property_id` (omit for every property I see finances of), `from` and
     * `to` (YYYY-MM-DD; default this month). Totals, each property's
     * figures, and billed and collected for the 12 months ending with `to`'s
     * month. `properties` lists every property I may pick. `previous` holds
     * the period figures of the period just before (as many whole months, or
     * days), for "vs last period"; the property and room views have it too.
     */
    public function summary(Request $request): JsonResponse
    {
        [$allowed, $properties, $from, $to] = $this->portfolioScope($request);

        return ApiResponse::ok([
            'properties' => $allowed->map(fn (Property $p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ] + $this->overview->portfolio($properties, $from, $to));
    }

    /**
     * A property's finances
     *
     * `from` and `to` as above. The property's totals, the trend, and each
     * room's figures (money is counted in a room where it was applied, so
     * `totals.collected.unapplied` is what is not in any room yet). A `room`
     * of null holds money of rooms since deleted.
     */
    public function property(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ViewCollections);
        [$from, $to] = $this->period($this->validatePeriod($request));

        return ApiResponse::ok($this->overview->property($property, $from, $to));
    }

    /**
     * A room's finances
     *
     * `from` and `to` as above. The room's figures, its trend, and each stay
     * in the room during the period with whole-stay totals (billed and paid
     * since move-in, unpaid and deposit held today).
     */
    public function room(Request $request, Room $room): JsonResponse
    {
        $this->authorizeProperty($request, $room->property, A::ViewCollections);
        [$from, $to] = $this->period($this->validatePeriod($request));

        return ApiResponse::ok($this->overview->room($room, $from, $to));
    }

    /**
     * A stay's finances
     *
     * From move-in to today: what was paid at move-in, each bill with what is
     * still unpaid, the payments applied to its bills, the totals, and how the
     * deposit was settled.
     */
    public function stay(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ViewCollections);

        return ApiResponse::ok($this->overview->stay($tenancy));
    }

    /**
     * Link to my financial statement (PDF)
     *
     * The same `property_id`, `from` and `to` as My finances: every property,
     * or one property by room.
     */
    public function summaryPdfLink(Request $request): JsonResponse
    {
        [, $properties, $from, $to] = $this->portfolioScope($request);

        return $this->linkTo('finance', ['properties' => $properties->pluck('id')->implode(',')] + $this->dates($from, $to));
    }

    /**
     * Link to a property's financial statement (PDF)
     */
    public function propertyPdfLink(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ViewCollections);
        [$from, $to] = $this->period($this->validatePeriod($request));

        return $this->linkTo('finance', ['properties' => (string) $property->id] + $this->dates($from, $to));
    }

    /**
     * Link to a room's financial statement (PDF)
     */
    public function roomPdfLink(Request $request, Room $room): JsonResponse
    {
        $this->authorizeProperty($request, $room->property, A::ViewCollections);
        [$from, $to] = $this->period($this->validatePeriod($request));

        return $this->linkTo('finance-room', ['room' => $room->id] + $this->dates($from, $to));
    }

    /**
     * Link to a stay's statement (PDF)
     */
    public function stayPdfLink(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ViewCollections);

        return $this->linkTo('finance-stay', ['tenancy' => $tenancy->id]);
    }

    /**
     * The properties I may see finances of, the ones picked, and the period.
     *
     * @return array{0: Collection<int, Property>, 1: Collection<int, Property>, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function portfolioScope(Request $request): array
    {
        $data = $this->validatePeriod($request, ['property_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $allowed = Property::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'owner_id'])
            ->filter(fn (Property $p) => PropertyAccess::can($user, A::ViewCollections, $p))->values();
        abort_if($allowed->isEmpty(), 403, 'Only the owner and Managers see finances.');

        $properties = isset($data['property_id']) ? $allowed->where('id', (int) $data['property_id'])->values() : $allowed;
        if ($properties->isEmpty()) {
            throw ValidationException::withMessages(['property_id' => 'You cannot see the finances of that property.']);
        }

        return [$allowed, $properties, ...$this->period($data)];
    }

    /** @return array<string, mixed> */
    private function validatePeriod(Request $request, array $rules = []): array
    {
        return $request->validate($rules + [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(array $data): array
    {
        $today = ManilaDate::today();
        if (! isset($data['from'])) {
            return [$today->startOfMonth(), $today->endOfMonth()->startOfDay()];
        }

        return [ManilaDate::parse($data['from'])->startOfDay(), ManilaDate::parse($data['to'])->startOfDay()];
    }

    /** @return array{from: string, to: string} */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    private function linkTo(string $type, array $params): JsonResponse
    {
        return ApiResponse::ok([
            'url' => $this->documents->link($type, $params),
            'expires_in_minutes' => (int) config('dormlink.private_file_url_minutes'),
        ]);
    }
}
