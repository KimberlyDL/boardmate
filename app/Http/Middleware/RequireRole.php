<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:owner` / `role:platform_admin,owner`: the user must hold one of the
 * roles. (The app's "active role" only chooses which screens are shown; the
 * API checks the roles the account actually holds.)
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        foreach ($roles as $role) {
            if ($user?->hasAccountRole(UserRole::from($role))) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'You do not have access to this.', 'code' => 'forbidden_role'], 403);
    }
}
