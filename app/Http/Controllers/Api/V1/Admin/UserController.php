<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform admin: find accounts and suspend abuse.
 *
 * @group Admin
 */
class UserController extends Controller
{
    /**
     * List accounts
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'suspended' => ['nullable', 'boolean'],
        ]);

        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->input('search')).'%';
                $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term]));
            })
            ->when($request->filled('role'), fn ($q) => $q->role($request->input('role')))
            ->when($request->has('suspended'), fn ($q) => $request->boolean('suspended')
                ? $q->whereNotNull('suspended_at')
                : $q->whereNull('suspended_at'))
            ->with('ownerProfile.documents', 'roles')
            ->latest()
            ->paginate(25);

        return ApiResponse::ok(AdminUserResource::collection($users->items()), meta: [
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ]);
    }

    /**
     * Suspend an account
     *
     * Blocks login and signs the account out everywhere.
     */
    public function suspend(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user()) || $user->hasAccountRole(UserRole::PlatformAdmin)) {
            return response()->json(['message' => 'Platform admin accounts cannot be suspended here.', 'code' => 'cannot_suspend_admin'], 403);
        }

        $user->forceFill(['suspended_at' => now()])->save();
        $user->tokens()->delete();
        app(AuditService::class)->record(AuditEvent::AccountSuspended, $user, note: "{$user->name} ({$user->email})");

        // Owners can no longer approve a suspended boarder; their reservation frees the unit.
        $bookings = app(BookingService::class);
        $cancelled = $bookings->closeForSuspendedBoarder($user, $request->user());
        // An owner who cannot log in cannot review applicants either.
        if ($user->ownerProfile) {
            $cancelled += $bookings->closeForSuspendedOwner($user, $request->user());
        }
        $message = "{$user->name} is suspended.".($cancelled ? " {$cancelled} open booking(s) were closed." : '');

        return ApiResponse::ok(new AdminUserResource($user->load('ownerProfile', 'roles')), $message);
    }

    /**
     * Lift an account suspension
     */
    public function unsuspend(User $user): JsonResponse
    {
        $user->forceFill(['suspended_at' => null])->save();
        app(AuditService::class)->record(AuditEvent::AccountUnsuspended, $user, note: "{$user->name} ({$user->email})");

        return ApiResponse::ok(new AdminUserResource($user->load('ownerProfile', 'roles')), "{$user->name} can log in again.");
    }
}
