<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * @group Auth
 */
class EmailVerificationController extends Controller
{
    /**
     * Resend the confirmation email
     *
     * For people who cannot log in yet because their email is not confirmed.
     * Always answers the same way, so it cannot reveal which emails have
     * accounts.
     *
     * @unauthenticated
     */
    public function resend(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', strtolower(trim($request->input('email'))))->first();

        if ($user && ! $user->hasVerifiedEmail() && ! $user->isSuspended()) {
            $user->sendEmailVerificationNotification();
        }

        return ApiResponse::message('If that email has an unconfirmed account, we sent a new confirmation link.');
    }

    /**
     * Confirm an email address
     *
     * Opened from the link in the email. Redirects to the app's
     * /email-verified page with status=success, already or invalid.
     *
     * @unauthenticated
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        $valid = $user
            && $request->hasValidSignature()
            && hash_equals(sha1($user->getEmailForVerification()), $hash);

        if (! $valid) {
            return self::toApp('invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return self::toApp('already');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return self::toApp('success');
    }

    public static function toApp(string $status): RedirectResponse
    {
        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/email-verified?status='.$status);
    }
}
