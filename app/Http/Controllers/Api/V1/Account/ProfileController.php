<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\AuditEvent;
use App\Enums\FilePurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateBoarderProfileRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Account
 */
class ProfileController extends Controller
{
    /**
     * Current user
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($request->user()->load(['boarderProfile', 'ownerProfile'])));
    }

    /**
     * Update profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $request->user()->update($request->validated());

        return ApiResponse::ok(new UserResource($request->user()->load(['boarderProfile', 'ownerProfile'])), 'Profile saved.');
    }

    /**
     * Update emergency contact
     *
     * Boarder emergency contact (Features Guide §5, User profiles).
     */
    public function updateBoarderProfile(UpdateBoarderProfileRequest $request): JsonResponse
    {
        $request->user()->boarderProfile()->updateOrCreate([], $request->validated());

        return ApiResponse::ok(new UserResource($request->user()->load(['boarderProfile', 'ownerProfile'])), 'Emergency contact saved.');
    }

    /**
     * Upload profile photo
     *
     * jpg, png or webp, up to 5 MB. Replaces the previous photo.
     */
    public function storePhoto(Request $request, FileService $files): JsonResponse
    {
        $request->validate(['photo' => $files->rules(FilePurpose::ProfilePhoto)]);

        $user = $request->user();
        $old = $user->photo_path;

        $user->photo_path = $files->store($request->file('photo'), FilePurpose::ProfilePhoto);
        $user->save();

        $files->delete($old, FilePurpose::ProfilePhoto);

        return ApiResponse::ok(new UserResource($user->load(['boarderProfile', 'ownerProfile'])), 'Photo updated.');
    }

    /**
     * Remove profile photo
     */
    public function destroyPhoto(Request $request, FileService $files): JsonResponse
    {
        $user = $request->user();
        $files->delete($user->photo_path, FilePurpose::ProfilePhoto);
        $user->photo_path = null;
        $user->save();

        return ApiResponse::ok(new UserResource($user->load(['boarderProfile', 'ownerProfile'])), 'Photo removed.');
    }

    /**
     * Change password
     *
     * Signs out all other devices. Accounts created with Google that have no
     * password yet can set one without `current_password`.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $hadPassword = $user->hasPassword();
        $user->password = $request->input('password');
        $user->save();

        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
        app(AuditService::class)->record($hadPassword ? AuditEvent::PasswordChanged : AuditEvent::PasswordSet, $user);

        return ApiResponse::message($hadPassword
            ? 'Password changed.'
            : 'Password set. You can now also log in with your email and password.');
    }
}
