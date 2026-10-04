<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\OwnerVerificationStatus as Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\OwnerProfile;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform admin: verify owner accounts and suspend abuse.
 *
 * @group Admin
 */
class OwnerReviewController extends Controller
{
    /**
     * List owners
     *
     * Filter by status (default: pending, oldest first so the queue is fair)
     * and search by name, email or business name.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(Status::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = Status::tryFrom((string) $request->input('status')) ?? Status::Pending;

        $owners = User::query()
            ->whereHas('ownerProfile', fn ($q) => $q->where('verification_status', $status->value))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->input('search')).'%';
                $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(email) like ?', [$term])
                    ->orWhereHas('ownerProfile', fn ($p) => $p->whereRaw('lower(business_name) like ?', [$term])));
            })
            ->with('ownerProfile', 'roles')
            ->orderBy(
                OwnerProfile::select('submitted_at')->whereColumn('owner_profiles.user_id', 'users.id'),
                $status === Status::Pending ? 'asc' : 'desc',
            )
            ->paginate(20);

        return ApiResponse::ok(AdminUserResource::collection($owners->items()), meta: [
            'current_page' => $owners->currentPage(),
            'last_page' => $owners->lastPage(),
            'total' => $owners->total(),
            'counts' => $this->counts(),
        ]);
    }

    /**
     * Verify an owner
     */
    public function verify(Request $request, User $user, NotificationService $notifications): JsonResponse
    {
        return $this->transition($request, $user, [Status::Pending, Status::Rejected], Status::Verified, null, AuditEvent::OwnerVerified,
            fn () => $notifications->send($user, NotificationEvent::OwnerApplicationApproved));
    }

    /**
     * Reject an application
     *
     * @bodyParam reason string required Shown to the owner, who can fix it and apply again.
     */
    public function reject(Request $request, User $user, NotificationService $notifications): JsonResponse
    {
        $reason = $this->reason($request);

        return $this->transition($request, $user, [Status::Pending], Status::Rejected, $reason, AuditEvent::OwnerRejected,
            fn () => $notifications->send($user, NotificationEvent::OwnerApplicationRejected, ['reason' => $reason]));
    }

    /**
     * Suspend an owner
     *
     * Hides their listings. Their account still works (use account suspension
     * to block logins).
     *
     * @bodyParam reason string required Shown to the owner.
     */
    public function suspend(Request $request, User $user, NotificationService $notifications): JsonResponse
    {
        $reason = $this->reason($request);

        return $this->transition($request, $user, [Status::Pending, Status::Verified], Status::Suspended, $reason, AuditEvent::OwnerSuspended,
            fn () => $notifications->send($user, NotificationEvent::OwnerSuspended, ['reason' => $reason]));
    }

    /**
     * Reinstate a suspended owner
     */
    public function reinstate(Request $request, User $user, NotificationService $notifications): JsonResponse
    {
        return $this->transition($request, $user, [Status::Suspended], Status::Verified, null, AuditEvent::OwnerReinstated,
            fn () => $notifications->send($user, NotificationEvent::OwnerApplicationApproved));
    }

    /** @param  list<Status>  $from */
    private function transition(Request $request, User $user, array $from, Status $to, ?string $reason, AuditEvent $event, callable $notify): JsonResponse
    {
        $profile = $user->ownerProfile;
        abort_unless($profile, 404, 'This account has no owner application.');

        if (! in_array($profile->verification_status, $from, true)) {
            return response()->json([
                'message' => "This owner is {$profile->verification_status->label()}; that action is not available.",
                'code' => 'invalid_owner_status',
            ], 409);
        }

        $before = $profile->verification_status;
        $profile->forceFill([
            'verification_status' => $to,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
            'review_reason' => $reason,
        ])->save();

        app(AuditService::class)->record(
            $event, $profile, ['verification_status' => [$before, $to]],
            owner: $user, reason: $reason, note: $user->ownerDisplayName(),
        );

        $notify();

        return ApiResponse::ok(new AdminUserResource($user->load('ownerProfile', 'roles')), "Owner is now {$to->label()}.");
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = OwnerProfile::query()
            ->selectRaw('verification_status, count(*) as total')
            ->groupBy('verification_status')
            ->pluck('total', 'verification_status');

        return collect(Status::cases())->mapWithKeys(fn (Status $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }
}
