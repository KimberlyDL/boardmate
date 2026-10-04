<?php

namespace App\Http\Resources;

use App\Models\CaretakerInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CaretakerInvitation */
class CaretakerInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status();

        return [
            'id' => $this->id,
            'email' => $this->email,
            'access_level' => $this->access_level->value,
            'access_level_label' => $this->access_level->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
