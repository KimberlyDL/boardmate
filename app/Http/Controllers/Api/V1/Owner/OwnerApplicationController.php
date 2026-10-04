<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\OwnerVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Owner
 */
class OwnerApplicationController extends Controller
{
    /**
     * Apply as an owner
     *
     * Any signed-in account can apply. The account gets the owner role right
     * away (so the owner can start setting up), but listings stay hidden until
     * a platform admin verifies the account. A rejected owner can apply again.
     */
    public function store(Request $request, NotificationService $notifications): JsonResponse
    {
        $user = $request->user();
        $profile = $user->ownerProfile;

        if ($profile && $profile->verification_status !== OwnerVerificationStatus::Rejected) {
            $message = match ($profile->verification_status) {
                OwnerVerificationStatus::Pending => 'Your application is already being reviewed.',
                OwnerVerificationStatus::Verified => 'Your owner account is already verified.',
                default => 'Your owner account is suspended. Contact BoardMate support.',
            };

            return response()->json(['message' => $message, 'code' => 'owner_application_exists'], 409);
        }

        $data = $request->validate([
            'business_name' => ['nullable', 'string', 'max:120'],
            'application_notes' => ['required', 'string', 'min:20', 'max:2000'],
            'phone' => [$user->phone ? 'nullable' : 'required', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
        ], [
            'application_notes.required' => 'Tell us about your property: where it is and how many rooms or beds it has.',
            'application_notes.min' => 'Please add a little more detail about your property.',
            'phone.required' => 'Add a mobile number so we can reach you about your application.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        DB::transaction(function () use ($user, $data) {
            if (! empty($data['phone'])) {
                $user->phone = $data['phone'];
            }

            $profile = $user->ownerProfile()->firstOrNew();
            $profile->fill([
                'business_name' => $data['business_name'] ?? null,
                'application_notes' => $data['application_notes'],
            ]);
            $profile->forceFill([
                'verification_status' => OwnerVerificationStatus::Pending,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_reason' => null,
            ])->save();

            $user->assignRole(UserRole::Owner->value);
            $user->active_role = UserRole::Owner->value;
            $user->save();
        });

        app(AuditService::class)->record(AuditEvent::OwnerApplied, $user->ownerProfile, owner: $user);

        $notifications->send(
            User::role(UserRole::PlatformAdmin->value)->get(),
            NotificationEvent::OwnerApplicationSubmitted,
            ['owner_name' => $user->name],
        );

        return ApiResponse::ok(
            new UserResource($user->load(['boarderProfile', 'ownerProfile'])),
            'Application sent. You can start setting up while we review it.',
        );
    }

    /**
     * Update business name and payment instructions
     *
     * The GCash and bank details boarders see when they pay. Only the owner
     * can change them (never a caretaker).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['nullable', 'string', 'max:120'],
            'gcash_name' => ['nullable', 'string', 'max:120'],
            'gcash_number' => ['nullable', 'string', 'regex:/^[0-9+\-\s]{10,20}$/'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_name' => ['nullable', 'string', 'max:120', 'required_with:bank_account_number'],
            'bank_account_number' => ['nullable', 'string', 'max:50', 'required_with:bank_name'],
        ], [
            'gcash_number.regex' => 'Enter a valid GCash mobile number.',
        ]);

        $profile = $request->user()->ownerProfile;
        $before = $profile->only(array_keys($data));
        $profile->update($data);

        $changes = AuditDiff::between($before, $profile->only(array_keys($data)));
        if ($changes !== []) {
            app(AuditService::class)->record(AuditEvent::OwnerDetailsChanged, $profile, $changes, owner: $request->user());
        }

        return ApiResponse::ok(
            new UserResource($request->user()->load(['boarderProfile', 'ownerProfile'])),
            'Saved.',
        );
    }
}
