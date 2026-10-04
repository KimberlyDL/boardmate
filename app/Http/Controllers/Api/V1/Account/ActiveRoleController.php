<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Account
 */
class ActiveRoleController extends Controller
{
    /**
     * Switch role
     *
     * One account can be, for example, both an owner and a boarder (Platform
     * rule: role switching). Chooses which screens the app shows; only roles
     * the account holds are allowed.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $held = array_map(fn (UserRole $r) => $r->value, $user->accountRoles());

        $request->validate(['role' => ['required', Rule::in($held)]], [
            'role.in' => 'You do not have that role.',
        ]);

        $user->forceFill(['active_role' => $request->input('role')])->save();

        return ApiResponse::ok(new UserResource($user->load(['boarderProfile', 'ownerProfile'])));
    }
}
