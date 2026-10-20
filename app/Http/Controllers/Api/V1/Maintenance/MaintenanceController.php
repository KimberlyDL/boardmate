<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Authorization\PropertyAccess;
use App\Enums\FilePurpose;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceUrgency;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Maintenance\MaintenanceRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\User;
use App\Services\Files\Contracts\FileService;
use App\Services\Maintenance\MaintenanceService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance requests (D1). Boarders report problems in the place they stay;
 * the owner and caretakers work through them. All caretakers move a request
 * along, assign it and add notes; the owner or a Manager also records the
 * cost and cancels.
 *
 * @group Maintenance
 */
class MaintenanceController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly MaintenanceService $maintenance, private readonly FileService $files) {}

    /**
     * My requests
     *
     * Everything the signed-in boarder reported, newest first, including
     * from places they left.
     */
    public function mine(Request $request): JsonResponse
    {
        $items = MaintenanceRequest::where('reported_by', $request->user()->id)
            ->with(['property:id,name', 'room', 'reporter:id,name', 'assignee:id,name'])->withCount('photos')
            ->latest('id')->limit(100)->get();

        return ApiResponse::ok($items->map(fn (MaintenanceRequest $r) => new MaintenanceRequestResource($r))->values());
    }

    /**
     * Open requests at my properties
     *
     * For the owner's and caretakers' home screens: each property with open
     * requests, how many, and how many of them are urgent.
     */
    public function summary(Request $request): JsonResponse
    {
        $properties = Property::query()->visibleTo($request->user())
            ->withCount([
                'maintenanceRequests as open_count' => fn ($q) => $q->whereIn('status', MaintenanceStatus::openValues()),
                'maintenanceRequests as urgent_count' => fn ($q) => $q->whereIn('status', MaintenanceStatus::openValues())->where('urgency', MaintenanceUrgency::Urgent->value),
            ])
            ->orderBy('name')->get()
            ->filter(fn (Property $p) => $p->open_count > 0 && PropertyAccess::can($request->user(), A::HandleMaintenance, $p));

        return ApiResponse::ok($properties->map(fn (Property $p) => [
            'property_id' => $p->id,
            'property_name' => $p->name,
            'open_count' => (int) $p->open_count,
            'urgent_count' => (int) $p->urgent_count,
        ])->values());
    }

    /**
     * Report a problem
     *
     * For the boarder's current stay (their room). Multipart: `category`,
     * `description`, `urgency` (normal or urgent), up to 4 `photos[]`.
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $item = $this->maintenance->report($request->user(), $data, $request->file('photos') ?? []);

        return ApiResponse::created($this->detail($item, $request->user()), 'Request sent. The owner and caretakers can see it now.');
    }

    /**
     * A property's requests
     *
     * `status`: open (default: submitted or in progress), resolved, done
     * (closed or cancelled) or all. Urgent ones first, then newest.
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::HandleMaintenance);
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'resolved', 'done', 'all'])]]);
        $statuses = match ($data['status'] ?? 'open') {
            'open' => MaintenanceStatus::openValues(),
            'resolved' => [MaintenanceStatus::Resolved->value],
            'done' => [MaintenanceStatus::Closed->value, MaintenanceStatus::Cancelled->value],
            'all' => null,
        };

        $items = $property->maintenanceRequests()
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->with(['property:id,name', 'room', 'reporter:id,name', 'assignee:id,name'])->withCount('photos')
            ->orderByRaw("case when urgency = 'urgent' and status in ('submitted', 'in_progress') then 0 else 1 end")
            ->latest('id')->limit(200)->get();

        return ApiResponse::ok($items->map(fn (MaintenanceRequest $r) => new MaintenanceRequestResource($r, staff: true))->values());
    }

    /**
     * Log a problem (staff)
     *
     * Like a boarder's report, with an optional `room_id` (none = the whole place).
     */
    public function log(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::HandleMaintenance);
        $data = $request->validate($this->rules() + ['room_id' => ['nullable', 'integer']]);
        $item = $this->maintenance->log($property, $request->user(), $data, $request->file('photos') ?? []);

        return ApiResponse::created($this->detail($item, $request->user()), 'Request logged.');
    }

    /**
     * Get a request
     *
     * For the one who reported it, or the property's owner and caretakers.
     * Includes photos (expiring links), the history, and what the viewer may do.
     */
    public function show(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeView($request->user(), $maintenanceRequest);

        return ApiResponse::ok($this->detail($maintenanceRequest, $request->user()));
    }

    /**
     * Start work
     */
    public function start(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeStaff($request->user(), $maintenanceRequest, A::HandleMaintenance);
        $item = $this->maintenance->start($maintenanceRequest, $request->user());

        return ApiResponse::ok($this->detail($item, $request->user()), 'Marked as in progress.');
    }

    /**
     * Mark as fixed
     *
     * Optional `resolution`: what was done. The boarder is asked to confirm.
     */
    public function resolve(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeStaff($request->user(), $maintenanceRequest, A::HandleMaintenance);
        $data = $request->validate(['resolution' => ['nullable', 'string', 'max:1000']]);
        $item = $this->maintenance->resolve($maintenanceRequest, $request->user(), $data['resolution'] ?? null);

        return ApiResponse::ok($this->detail($item, $request->user()), 'Marked as fixed.');
    }

    /**
     * Cancel a request
     *
     * The owner or a Manager, with a `reason` the boarder sees; or the one
     * who reported it, while nobody has started on it.
     */
    public function cancel(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $maintenanceRequest);
        $isStaff = $this->isStaff($user, $maintenanceRequest);
        if ($isStaff) {
            abort_unless(PropertyAccess::can($user, A::ManageMaintenance, $maintenanceRequest->property), 403, 'Only the owner or a Manager can cancel a request.');
        } else {
            abort_unless($maintenanceRequest->status === MaintenanceStatus::Submitted, 403, 'Work has started on this request. Ask the owner or caretaker.');
        }
        $data = $request->validate(['reason' => [$isStaff ? 'required' : 'nullable', 'string', 'max:255']]);
        $item = $this->maintenance->cancel($maintenanceRequest, $user, $data['reason'] ?? null);

        return ApiResponse::ok($this->detail($item, $user), 'Request cancelled.');
    }

    /**
     * Assign a request
     *
     * `user_id`: the owner or one of the property's caretakers, or null.
     */
    public function assign(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeStaff($request->user(), $maintenanceRequest, A::HandleMaintenance);
        $data = $request->validate(['user_id' => ['present', 'nullable', 'integer']]);
        $to = $data['user_id'] ? User::find($data['user_id']) : null;
        if ($data['user_id'] && ! $to) {
            throw ValidationException::withMessages(['user_id' => 'Choose the owner or a caretaker of this property.']);
        }
        $item = $this->maintenance->assign($maintenanceRequest, $to, $request->user());

        return ApiResponse::ok($this->detail($item, $request->user()), $to ? "Assigned to {$to->name}." : 'Unassigned.');
    }

    /**
     * Record the repair cost
     *
     * `repair_cost_centavos`, or null to clear it. For the owner's records;
     * it is not billed to anyone.
     */
    public function cost(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeStaff($request->user(), $maintenanceRequest, A::ManageMaintenance);
        $data = $request->validate(['repair_cost_centavos' => ['present', 'nullable', 'integer', 'min:0', 'max:100000000']]);
        $item = $this->maintenance->setCost($maintenanceRequest, $data['repair_cost_centavos'], $request->user());

        return ApiResponse::ok($this->detail($item, $request->user()), 'Repair cost saved.');
    }

    /**
     * Confirm the fix
     *
     * The one who reported it, once it is marked as fixed. Otherwise it
     * closes by itself 3 days later.
     */
    public function confirm(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeReporter($request->user(), $maintenanceRequest);
        $item = $this->maintenance->confirmFixed($maintenanceRequest, $request->user());

        return ApiResponse::ok($this->detail($item, $request->user()), 'Thanks. The request is closed.');
    }

    /**
     * Still broken
     *
     * The one who reported it sends it back to In progress with a `reason`.
     */
    public function reopen(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeReporter($request->user(), $maintenanceRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $item = $this->maintenance->stillBroken($maintenanceRequest, $request->user(), trim($data['reason']));

        return ApiResponse::ok($this->detail($item, $request->user()), 'Sent back to the owner and caretakers.');
    }

    /**
     * Charge the boarder for the repair
     *
     * Only when the boarder caused the damage; never automatic. A one-time
     * charge on their bills with a `reason` they see (`amount_centavos`,
     * optional `description` and `due_on`). Once per request.
     */
    public function charge(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeStaff($request->user(), $maintenanceRequest, A::ManageMaintenance);
        $data = $request->validate([
            'amount_centavos' => ['required', 'integer', 'min:1', 'max:100000000'],
            'description' => ['nullable', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:255'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.ManilaDate::today()->toDateString()],
        ]);
        $item = $this->maintenance->charge($maintenanceRequest, $data['amount_centavos'], $data['description'] ?? null, $data['reason'], $data['due_on'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($item, $request->user()), 'Charge added to the boarder\'s bills.');
    }

    /**
     * Set the unit Not ready (or ready)
     *
     * `not_ready`, optional `reason`, and `unit_id` when the request has no
     * stay (a unit of its room). Hidden from bookings while not ready.
     */
    public function notReady(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeStaff($request->user(), $maintenanceRequest, A::ManageMaintenance);
        $data = $request->validate([
            'not_ready' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'unit_id' => ['nullable', 'integer'],
        ]);
        $item = $this->maintenance->setNotReady($maintenanceRequest, (bool) $data['not_ready'], $data['reason'] ?? null, $data['unit_id'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($item, $request->user()), $data['not_ready'] ? 'Marked Not ready.' : 'Marked ready again.');
    }

    /**
     * Add a note
     *
     * By the one who reported it or the property's staff; everyone on the
     * request sees it.
     */
    public function note(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorizeView($request->user(), $maintenanceRequest);
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $item = $this->maintenance->addNote($maintenanceRequest, $request->user(), trim($data['body']));

        return ApiResponse::ok($this->detail($item, $request->user()), 'Note added.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(MaintenanceCategory::class)],
            'description' => ['required', 'string', 'max:2000'],
            'urgency' => ['nullable', Rule::enum(MaintenanceUrgency::class)],
            'photos' => ['sometimes', 'array', 'max:'.MaintenanceRequest::MAX_PHOTOS],
            'photos.*' => $this->files->rules(FilePurpose::MaintenancePhoto),
        ];
    }

    private function isStaff(User $user, MaintenanceRequest $item): bool
    {
        return PropertyAccess::can($user, A::HandleMaintenance, $item->property);
    }

    /** The reporter or the property's staff; anyone else gets 404. */
    private function authorizeView(User $user, MaintenanceRequest $item): void
    {
        abort_unless($item->reported_by === $user->id || $this->isStaff($user, $item), 404);
    }

    private function authorizeReporter(User $user, MaintenanceRequest $item): void
    {
        $this->authorizeView($user, $item);
        abort_unless($item->reported_by === $user->id, 403, 'Only the one who reported it can do this.');
    }

    private function authorizeStaff(User $user, MaintenanceRequest $item, A $ability): void
    {
        $this->authorizeView($user, $item);
        abort_unless(PropertyAccess::can($user, $ability, $item->property), 403, 'You do not have permission to do this for this property.');
    }

    private function detail(MaintenanceRequest $item, User $viewer): MaintenanceRequestResource
    {
        $item->refresh()->load(['property', 'room', 'reporter:id,name', 'assignee:id,name', 'photos', 'notes.author:id,name', 'unit', 'chargeBill.lines', 'tenancy.unit']);
        $staff = $this->isStaff($viewer, $item);

        return new MaintenanceRequestResource($item, staff: $staff, detail: true, can: [
            'edit' => $staff,
            'manage' => $staff && PropertyAccess::can($viewer, A::ManageMaintenance, $item->property),
        ]);
    }
}
