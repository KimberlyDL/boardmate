<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a platform admin sees about an account: identity, roles, status and
 * the owner application. Never billing (Roles: admin does not see billing).
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'roles' => array_map(fn (UserRole $r) => $r->value, $this->accountRoles()),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'owner_profile' => $this->ownerProfile ? new OwnerProfileResource($this->ownerProfile) : null,
        ];
    }
}
