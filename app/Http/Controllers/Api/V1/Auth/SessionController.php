<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\Auth\Concerns\IssuesApiTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Auth
 */
class SessionController extends Controller
{
    use IssuesApiTokens;

    /**
     * Log in
     *
     * Returns a bearer token for the API. Refused with 403 and
     * `code: email_unverified` until the email is confirmed, or
     * `code: account_suspended` for suspended accounts.
     *
     * @unauthenticated
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        // Google-only accounts have no password, so Hash::check() is false.
        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect.',
            ]);
        }

        return $this->refuseSignIn($user) ?? $this->issueToken($user, $request->input('device_name'));
    }

    /**
     * Log out
     *
     * Revokes the token used for this request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::message('Logged out.');
    }
}
