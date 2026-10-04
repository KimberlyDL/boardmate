<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The one place a login (password or Google) turns into an API token, so the
 * account checks are the same for every sign-in method.
 */
trait IssuesApiTokens
{
    /** A refusal response if this account may not sign in, otherwise null. */
    protected function refuseSignIn(User $user): ?JsonResponse
    {
        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'This account is suspended. Contact BoardMate support.',
                'code' => 'account_suspended',
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please confirm your email first. Check your inbox for the link we sent, or ask for a new one.',
                'code' => 'email_unverified',
            ], 403);
        }

        return null;
    }

    protected function issueToken(User $user, ?string $deviceName, int $status = 200): JsonResponse
    {
        $token = $user->createToken($deviceName ?: 'app')->plainTextToken;

        return ApiResponse::ok([
            'token' => $token,
            'user' => new UserResource($user->load(['boarderProfile', 'ownerProfile'])),
        ], status: $status);
    }
}
