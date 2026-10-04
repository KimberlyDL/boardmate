<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group Account
 */
class EmailChangeController extends Controller
{
    /**
     * Change email
     *
     * Sends a confirmation link to the new address and a notice to the
     * current one. The current email stays on the account until the new one
     * is confirmed. `current_password` is required unless the account was
     * created with Google and has no password.
     */
    public function store(Request $request, NotificationService $notifications): JsonResponse
    {
        $user = $request->user();

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $request->validate([
            'email' => [
                'required', 'email', 'max:255',
                Rule::notIn([$user->email]),
                Rule::unique('users', 'email'),
            ],
            'current_password' => $user->hasPassword()
                ? ['required', 'current_password:sanctum']
                : ['nullable'],
        ], [
            'email.not_in' => 'That is already your email.',
            'email.unique' => 'Another account already uses this email.',
        ]);

        $user->forceFill([
            'pending_email' => $request->input('email'),
            'pending_email_requested_at' => now(),
        ])->save();

        $user->sendEmailChangeVerification();
        $notifications->send($user, NotificationEvent::EmailChangeNotice, [
            'masked_email' => self::mask($user->pending_email),
        ]);

        return ApiResponse::ok(
            new UserResource($user->load(['boarderProfile', 'ownerProfile'])),
            "We sent a confirmation link to {$user->pending_email}. Your email changes once you open it.",
        );
    }

    /**
     * Resend the new-email confirmation
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->pending_email, 404, 'There is no email change waiting for confirmation.');

        $user->sendEmailChangeVerification();

        return ApiResponse::message("We sent a new confirmation link to {$user->pending_email}.");
    }

    /**
     * Cancel an email change
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['pending_email' => null, 'pending_email_requested_at' => null])->save();

        return ApiResponse::ok(new UserResource($user->load(['boarderProfile', 'ownerProfile'])), 'Email change cancelled.');
    }

    /**
     * Confirm a new email
     *
     * Opened from the link sent to the new address. Redirects to the app's
     * /email-verified page with status=changed or invalid.
     *
     * @unauthenticated
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        $valid = $user
            && $user->pending_email
            && $request->hasValidSignature()
            && hash_equals(sha1($user->pending_email), $hash);

        if (! $valid) {
            return EmailVerificationController::toApp('invalid');
        }

        $changed = DB::transaction(function () use ($user) {
            // Someone may have signed up with the address in the meantime.
            if (User::withTrashed()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
                return false;
            }

            $oldEmail = $user->email;
            $user->forceFill([
                'email' => $user->pending_email,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_requested_at' => null,
            ])->save();

            app(AuditService::class)->record(AuditEvent::EmailChanged, $user, ['email' => [$oldEmail, $user->email]], actor: $user);

            return true;
        });

        return EmailVerificationController::toApp($changed ? 'changed' : 'taken');
    }

    /** kim.santos@gmail.com → k***@gmail.com */
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
