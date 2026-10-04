<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Authorization\PropertyAccess;
use App\Enums\AuditEvent;
use App\Enums\PropertyAbility as A;
use App\Enums\PropertyType;
use App\Enums\RentalMode;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\PropertyResource;
use App\Http\Responses\ApiResponse;
use App\Models\Building;
use App\Models\Property;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Booking\BookingService;
use App\Services\Properties\PropertySetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Properties
 */
class PropertyController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly AuditService $audit, private readonly PropertySetup $setup) {}

    /**
     * List properties
     *
     * Owners see their own; caretakers see the properties they are assigned
     * to. Nobody ever sees another owner's properties.
     */
    public function index(Request $request): JsonResponse
    {
        $properties = Property::query()
            ->visibleTo($request->user())
            ->with(['units', 'photos', 'building', 'owner.ownerProfile'])
            ->orderBy('name')
            ->get();

        $roles = PropertyAccess::rolesOn($request->user(), $properties);

        return ApiResponse::ok($properties->map(fn (Property $p) => new PropertyResource($p, role: $roles[$p->id])));
    }

    /**
     * Add a property
     *
     * Owner only. Creates the property with its first unit(s) and the guide's
     * default settings. Prices are in centavos.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasAccountRole(UserRole::Owner), 403, 'Only owners can add properties.');

        $data = $request->validate($this->detailRules($request, true) + [
            'type' => ['required', Rule::enum(PropertyType::class)],
            'rental_mode' => ['required', Rule::enum(RentalMode::class)],
            ...$this->unitSpecRules(),
        ]);

        $property = $this->setup->create(
            $user,
            collect($data)->only((new Property)->getFillable())->all(),
            RentalMode::from($data['rental_mode']),
            $data['units'] ?? [],
            $data['type'],
        );

        $this->audit->record(AuditEvent::PropertyCreated, $property, owner: $user, note: $property->name);

        return ApiResponse::created($this->detail($property), 'Property added. It stays a draft until you publish it.');
    }

    /**
     * View a property
     */
    public function show(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        return ApiResponse::ok($this->detail($property));
    }

    /**
     * Update details and location
     */
    public function update(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::EditDetails);

        $data = $request->validate($this->detailRules($request, false) + [
            'type' => ['sometimes', Rule::enum(PropertyType::class)],
        ]);

        $before = $property->only(array_keys($data));
        $property->fill(collect($data)->except('type')->all());
        if (isset($data['type'])) {
            $property->type = $data['type'];
        }
        $property->save();

        $changes = AuditDiff::between($before, $property->only(array_keys($data)));
        if ($changes !== []) {
            $this->audit->record(AuditEvent::PropertyUpdated, $property, $changes, owner: $property->owner, note: $property->name);
        }

        return ApiResponse::ok($this->detail($property), 'Saved.');
    }

    /**
     * Delete a property
     *
     * Owner only, and only when nobody is booked into or living in it. The
     * property is archived; its history stays.
     */
    public function destroy(Request $request, Property $property, BookingService $bookings): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::DeleteProperty);

        $blocks = $this->setup->deleteIfFree($property);

        if ($blocks->isNotEmpty()) {
            return response()->json([
                'message' => 'Someone is booked into or living in this property ('.$blocks->pluck('label')->implode(', ').'). It can be deleted once all units are free.',
                'code' => 'property_in_use',
            ], 409);
        }

        $declined = $bookings->closeForDeletedProperty($property, $request->user());
        $this->audit->record(AuditEvent::PropertyDeleted, $property, owner: $property->owner,
            note: $property->name.($declined ? " · {$declined} pending application(s) declined" : ''));

        return ApiResponse::message("{$property->name} was deleted.");
    }

    /**
     * Publish the listing
     *
     * Needs a verified owner, a map pin, a photo, rent on every unit and at
     * least one unit ready. Returns 422 with the missing items otherwise.
     */
    public function publish(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::PublishListing);

        $checklist = $this->setup->publishChecklist($property);
        $missing = array_values(array_filter($checklist, fn ($item) => ! $item['ok']));

        if ($missing !== []) {
            return response()->json([
                'message' => 'Finish these first: '.implode('; ', array_column($missing, 'label')).'.',
                'code' => 'publish_requirements',
                'checklist' => $checklist,
            ], 422);
        }

        if (! $property->is_published) {
            $property->forceFill(['is_published' => true, 'published_at' => now()])->save();
            $this->audit->record(AuditEvent::PropertyPublished, $property, owner: $property->owner, note: $property->name);
        }

        return ApiResponse::ok($this->detail($property), 'Published. Your listing can now be found by boarders.');
    }

    /**
     * Unpublish the listing
     */
    public function unpublish(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::PublishListing);

        if ($property->is_published) {
            $property->forceFill(['is_published' => false])->save();
            $this->audit->record(AuditEvent::PropertyUnpublished, $property, owner: $property->owner, note: $property->name);
        }

        return ApiResponse::ok($this->detail($property), 'Unpublished. The listing is hidden.');
    }

    /**
     * Switch rental mode
     *
     * Whole property ⇄ bedspaces. Refused while any unit is in use. The old
     * units are archived with their price history.
     */
    public function switchMode(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageUnits);

        $data = $request->validate([
            'rental_mode' => ['required', Rule::enum(RentalMode::class)],
            ...$this->unitSpecRules(),
        ]);

        $before = $property->rental_mode;
        $this->setup->switchMode($property, RentalMode::from($data['rental_mode']), $data['units'] ?? [], $request->user());

        $this->audit->record(AuditEvent::RentalModeSwitched, $property,
            ['rental_mode' => [$before, $property->rental_mode]], owner: $property->owner, note: $property->name);

        return ApiResponse::ok($this->detail($property->refresh()), 'Rental mode changed.');
    }

    private function detail(Property $property): PropertyResource
    {
        return new PropertyResource($property->load(['units', 'utilityAccounts', 'photos', 'building', 'owner.ownerProfile']), detail: true);
    }

    private function detailRules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'who_can_apply' => ['sometimes', 'nullable', 'string', 'max:120'],
            'building_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists(Building::class, 'id')->where('owner_id', $request->user()->id)],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:120'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:4,22', 'required_with:longitude'],   // Philippines
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:116,127', 'required_with:latitude'],
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
