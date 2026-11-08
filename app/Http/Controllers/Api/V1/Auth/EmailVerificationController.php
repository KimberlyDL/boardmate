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
     * Called by the website's /verify-email page (the link in the email
     * points there, so it can open the app) with the link's expires and
     * signature: answers `{status}` as JSON, success, already or invalid.
     * Opened directly (links sent before the change), it redirects to the
     * /email-verified page with the same status.
     *
     * @unauthenticated
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::find($id);

        $valid = $user
            && $request->hasValidSignature()
            && hash_equals(sha1($user->getEmailForVerification()), $hash);

        if (! $valid) {
            return self::answer($request, 'invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return self::answer($request, 'already');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return self::answer($request, 'success');
    }

    /** JSON for the website page; a redirect to /email-verified for a link opened directly. */
    public static function answer(Request $request, string $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return ApiResponse::ok(['status' => $status]);
        }

        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/email-verified?status='.$status);
    }
}
