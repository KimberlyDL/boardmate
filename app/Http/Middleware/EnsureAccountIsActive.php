<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended accounts (Platform admin: "suspend abuse") and accounts
 * whose email is not confirmed, on every authenticated request, even if they
 * still hold a valid token.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isSuspended()) {
            return response()->json([
                'message' => 'This account is suspended. Contact BoardMate support.',
                'code' => 'account_suspended',
            ], 403);
        }

        if ($user && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please confirm your email first.',
                'code' => 'email_unverified',
            ], 403);
        }

        return $next($request);
    }
}
