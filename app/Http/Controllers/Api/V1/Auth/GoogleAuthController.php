<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AuditEvent;
use App\Enums\FilePurpose;
use App\Enums\NotificationEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Auth\Concerns\IssuesApiTokens;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Files\Contracts\FileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\SocialAuth\Contracts\GoogleTokenVerifier;
use App\Services\SocialAuth\GoogleIdentity;
use App\Services\SocialAuth\InvalidGoogleToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @group Auth
 */
class GoogleAuthController extends Controller
{
    use IssuesApiTokens;

    /**
     * Continue with Google
     *
     * Send the ID token from Google's sign-in button. Logs in the account
     * linked to that Google account, or links an existing account with the
     * same email, or (with `consent: true`) creates a new boarder account.
     * Without consent, a new person gets 409 with `code: consent_required`
     * and nothing is created.
     *
     * @unauthenticated
     */
    public function __invoke(
        Request $request,
        GoogleTokenVerifier $verifier,
        NotificationService $notifications,
        FileService $files,
    ): JsonResponse {
        $request->validate([
            'id_token' => ['required', 'string'],
            'consent' => ['sometimes', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $google = $verifier->verify($request->input('id_token'));
        } catch (InvalidGoogleToken $e) {
            throw ValidationException::withMessages(['id_token' => $e->getMessage()]);
        }

        $user = User::firstWhere('google_id', $google->googleId)
            ?? $this->linkExisting($google);

        if (! $user) {
            if (! $request->boolean('consent')) {
                return response()->json([
                    'message' => 'Please agree to the privacy notice to create your BoardMate account.',
                    'code' => 'consent_required',
                ], 409);
            }

            $user = $this->createFromGoogle($google, $files);
            $notifications->send($user, NotificationEvent::Welcome);

            return $this->refuseSignIn($user) ?? $this->issueToken($user, $request->input('device_name'), 201);
        }

        return $this->refuseSignIn($user) ?? $this->issueToken($user, $request->input('device_name'));
    }

    /** Same email as an existing account: Google verified it, so link them. */
    private function linkExisting(GoogleIdentity $google): ?User
    {
        $user = User::firstWhere('email', $google->email);

        if ($user && $user->google_id !== null) {
            // Same email, but a different Google account than the one linked.
            throw ValidationException::withMessages([
                'id_token' => 'This email is already linked to a different Google account. Log in with your password instead.',
            ]);
        }

        if ($user) {
            $user->forceFill([
                'google_id' => $google->googleId,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            app(AuditService::class)->record(AuditEvent::GoogleLinked, $user, actor: $user);
        }

        return $user;
    }

    private function createFromGoogle(GoogleIdentity $google, FileService $files): User
    {
        $user = DB::transaction(function () use ($google) {
            $user = new User([
                'name' => $google->name,
                'email' => $google->email,
                'active_role' => UserRole::Boarder->value,
                'consented_at' => now(),
                'privacy_policy_version' => config('boardmate.privacy_policy_version'),
            ]);
            $user->forceFill(['google_id' => $google->googleId, 'email_verified_at' => now()])->save();

            $user->assignRole(UserRole::Boarder->value);
            $user->boarderProfile()->create();

            return $user;
        });

        $this->copyGooglePhoto($user, $google, $files);

        return $user;
    }

    /** Best effort: a missing photo never blocks sign-up. */
    private function copyGooglePhoto(User $user, GoogleIdentity $google, FileService $files): void
    {
        if (! $google->pictureUrl) {
            return;
        }

        try {
            $response = Http::timeout(5)->get($google->pictureUrl)->throw();
            $tmp = tempnam(sys_get_temp_dir(), 'gphoto');
            file_put_contents($tmp, $response->body());

            $file = new UploadedFile($tmp, 'google.jpg', $response->header('Content-Type') ?: 'image/jpeg', test: true);
            validator(['photo' => $file], ['photo' => $files->rules(FilePurpose::ProfilePhoto)])->validate();

            $user->forceFill(['photo_path' => $files->store($file, FilePurpose::ProfilePhoto)])->save();
            @unlink($tmp);
        } catch (Throwable $e) {
            Log::warning('Could not copy Google profile photo', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
