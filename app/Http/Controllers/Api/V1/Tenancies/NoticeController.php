<?php

namespace App\Http\Controllers\Api\V1\Tenancies;

use App\Enums\NoticeStatus;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Services\Tenancies\NoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notice and end dates (C1). The boarder (in a whole room, the leader) files
 * a notice; the owner or any caretaker approves or declines it, or sets,
 * changes or cancels an end date with a reason.
 *
 * @group Tenancies
 */
class NoticeController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly NoticeService $notices) {}

    /**
     * Give notice
     *
     * The last day of stay (`end_on`, today or later). Waits for the owner or
     * a caretaker. Fewer days than the property's minimum is flagged as short
     * notice; there is no fee.
     */
    public function file(Request $request, Tenancy $tenancy): JsonResponse
    {
        abort_unless($tenancy->tenant_id === $request->user()->id, 404);
        $data = $request->validate(['end_on' => ['required', 'date_format:Y-m-d']]);

        $notice = $this->notices->file($tenancy, $data['end_on'], $request->user());

        return ApiResponse::created($this->present($notice), 'Notice sent. The owner will confirm your last day.');
    }

    /**
     * Withdraw my notice
     *
     * Only while it is waiting for approval.
     */
    public function withdraw(Request $request, Tenancy $tenancy): JsonResponse
    {
        abort_unless($tenancy->tenant_id === $request->user()->id, 404);
        $this->notices->withdraw($tenancy, $request->user());

        return ApiResponse::message('Notice withdrawn.');
    }

    /**
     * A tenancy's notices
     *
     * Every end date asked for or set, newest first. Staff of the property
     * and the tenant.
     */
    public function history(Request $request, Tenancy $tenancy): JsonResponse
    {
        if ($tenancy->tenant_id !== $request->user()->id) {
            $this->authorizeProperty($request, $tenancy->property, A::View);
        }
        $notices = $tenancy->notices()->with(['filer:id,name', 'decider:id,name'])->get();

        return ApiResponse::ok($notices->map(fn (TenancyNotice $n) => $this->present($n))->values());
    }

    /**
     * Notices waiting for approval
     *
     * At this property, oldest first.
     */
    public function pending(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ApproveMoveOut);
        $notices = TenancyNotice::where('status', NoticeStatus::Pending->value)
            ->whereHas('tenancy', fn ($q) => $q->where('property_id', $property->id))
            ->with(['tenancy.tenant:id,name,phone', 'tenancy.room', 'tenancy.unit', 'filer:id,name'])
            ->orderBy('id')
            ->get();

        return ApiResponse::ok($notices->map(fn (TenancyNotice $n) => $this->present($n) + [
            'tenancy' => [
                'id' => $n->tenancy_id,
                'tenant' => ['id' => $n->tenancy->tenant->id, 'name' => $n->tenancy->tenant->name, 'phone' => $n->tenancy->tenant->phone],
                'room_label' => $n->tenancy->room->label(),
                'unit_label' => $n->tenancy->unit->label,
            ],
        ])->values());
    }

    /**
     * Approve a notice
     *
     * The tenancy becomes "Leaving" with that end date, and the unit shows
     * Leaving. No rent period starts after the end date.
     */
    public function approve(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ApproveMoveOut);
        $tenancy = $this->notices->approve($tenancy, $request->user());

        return ApiResponse::ok($this->state($tenancy), 'Notice approved. Last day: '.$tenancy->end_on->format('M j, Y').'.');
    }

    /**
     * Decline a notice
     *
     * `reason` is required and sent to the boarder; they stay on.
     */
    public function decline(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ApproveMoveOut);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->notices->decline($tenancy, $data['reason'], $request->user());

        return ApiResponse::message('Notice declined.');
    }

    /**
     * Set or change the end date
     *
     * When the boarder gave no notice (SC-11), for an agreed date, or to
     * extend a stay (SC-29). Up to 30 days back for someone who already left.
     * `reason` is required. A notice still waiting is replaced.
     */
    public function setEndDate(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ApproveMoveOut);
        $data = $request->validate([
            'end_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $tenancy = $this->notices->setEndDate($tenancy, $data['end_on'], $data['reason'], $request->user());

        return ApiResponse::ok($this->state($tenancy), 'End date set: '.$tenancy->end_on->format('M j, Y').'.');
    }

    /**
     * Cancel the end date
     *
     * The boarder stays on, month to month. `reason` is required.
     */
    public function cancelEndDate(Request $request, Tenancy $tenancy): JsonResponse
    {
        $this->authorizeProperty($request, $tenancy->property, A::ApproveMoveOut);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $tenancy = $this->notices->cancelEndDate($tenancy, $data['reason'], $request->user());

        return ApiResponse::ok($this->state($tenancy), 'End date cancelled.');
    }

    /** @return array<string, mixed> */
    private function present(TenancyNotice $n): array
    {
        return [
            'id' => $n->id,
            'end_on' => $n->end_on->toDateString(),
            'status' => $n->status->value,
            'status_label' => $n->status->label(),
            'is_short_notice' => $n->is_short_notice,
            'reason' => $n->reason,
            'filed_by' => $n->relationLoaded('filer') ? $n->filer?->name : null,
            'filed_at' => $n->created_at?->toIso8601String(),
            'decided_by' => $n->relationLoaded('decider') ? $n->decider?->name : null,
            'decided_at' => $n->decided_at?->toIso8601String(),
            'decline_reason' => $n->decline_reason,
        ];
    }

    /** @return array{id: int, status: string, status_label: string, end_on: ?string, unit_status: string} */
    private function state(Tenancy $tenancy): array
    {
        $tenancy->load('unit');

        return [
            'id' => $tenancy->id,
            'status' => $tenancy->status->value,
            'status_label' => $tenancy->status->label(),
            'end_on' => $tenancy->end_on?->toDateString(),
            'unit_status' => $tenancy->unit->status->value,
        ];
    }
}
