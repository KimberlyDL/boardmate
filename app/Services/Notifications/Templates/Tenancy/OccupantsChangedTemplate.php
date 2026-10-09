<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner and Managers: a room's leader changed who stays. */
class OccupantsChangedTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Who stays in room {$data['room_code']} changed")
            ->line("{$data['leader_name']} {$data['summary']} in room {$data['room_code']} at {$data['property_name']}.");
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Room {$data['room_code']}: who stays changed",
            'body' => "{$data['leader_name']} {$data['summary']} ({$data['property_name']}).",
            'action_url' => '/properties/'.$data['property_id'],
        ];
    }
}
