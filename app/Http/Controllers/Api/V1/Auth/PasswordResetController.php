<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * @group Auth
 */
class PasswordResetController extends Controller
{
    /**
     * Request a password reset link
     *
     * Always answers the same way, so nobody can use it to check which emails
     * have accounts.
     *
     * @unauthenticated
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink(['email' => strtolower(trim($request->input('email')))]);

        return ApiResponse::message('If an account uses that email, we sent a link to reset the password.');
    }

    /**
     * Reset the password
     *
     * Uses the token from the email link. Signs out every device.
     *
     * @unauthenticated
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => strtolower(trim($request->input('email'))),
                'token' => $request->input('token'),
                'password' => $request->input('password'),
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password]);

                // Opening the emailed link proves they own the address.
                if (! $user->hasVerifiedEmail()) {
                    $user->email_verified_at = now();
                }

                $user->save();
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'This reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        return ApiResponse::message('Your password has been reset. You can now log in.');
    }
}
