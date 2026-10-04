<?php

namespace App\Http\Resources;

use App\Enums\FilePurpose;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'pending_email' => $this->pending_email,
            'has_password' => $this->hasPassword(),
            'google_linked' => $this->google_id !== null,
            'photo_url' => app(FileService::class)->url($this->photo_path, FilePurpose::ProfilePhoto),
            'roles' => array_map(fn (UserRole $r) => $r->value, $this->accountRoles()),
            'active_role' => $this->active_role,
            'consented_at' => $this->consented_at?->toIso8601String(),
            'boarder_profile' => $this->whenLoaded('boarderProfile', fn () => $this->boarderProfile ? [
                'emergency_contact_name' => $this->boarderProfile->emergency_contact_name,
                'emergency_contact_phone' => $this->boarderProfile->emergency_contact_phone,
                'emergency_contact_relationship' => $this->boarderProfile->emergency_contact_relationship,
            ] : null),
            'owner_profile' => $this->whenLoaded('ownerProfile', fn () => $this->ownerProfile
                ? new OwnerProfileResource($this->ownerProfile)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
