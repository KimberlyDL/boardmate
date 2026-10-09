<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To a leader who was replaced. */
class LeaderEndedTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject("You no longer lead room {$data['room_code']}");
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'You are no longer the room leader',
            'body' => "{$data['new_leader']} now leads room {$data['room_code']} at {$data['property_name']}.",
            'action_url' => '/boarder/home',
        ];
    }
}
