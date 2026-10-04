<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\NotificationEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * @group Auth
 */
class RegisterController extends Controller
{
    /**
     * Sign up
     *
     * Creates a boarder account (every new account starts as a boarder; owner
     * and caretaker roles are added later), records privacy consent and sends
     * the confirmation email. No token is returned: the email must be
     * confirmed before the first login.
     *
     * @unauthenticated
     */
    public function __invoke(RegisterRequest $request, NotificationService $notifications): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->string('name')->trim()->toString(),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'password' => $request->input('password'),
                'active_role' => UserRole::Boarder->value,
                'consented_at' => now(),
                'privacy_policy_version' => config('boardmate.privacy_policy_version'),
            ]);

            $user->assignRole(UserRole::Boarder->value);
            $user->boarderProfile()->create();

            return $user;
        });

        $user->sendEmailVerificationNotification();
        $notifications->send($user, NotificationEvent::Welcome);

        return ApiResponse::created(
            ['email' => $user->email],
            'Account created. Check your email and tap the link to confirm your address, then log in.',
        );
    }
}
