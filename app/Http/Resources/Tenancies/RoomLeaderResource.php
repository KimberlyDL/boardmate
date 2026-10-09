<?php

namespace App\Http\Resources\Tenancies;

use App\Enums\FilePurpose;
use App\Models\RoomLeader;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A room's leader and the consent behind the appointment. The caller loads `user`.
 *
 * @mixin RoomLeader
 */
class RoomLeaderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'photo_url' => app(FileService::class)->url($this->user->photo_path, FilePurpose::ProfilePhoto),
            ],
            'started_on' => $this->started_on->toDateString(),
            'consent_recorded_at' => $this->consent_recorded_at->toIso8601String(),
        ];
    }
}
