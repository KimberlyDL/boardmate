<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the person named leader of a room. */
class LeaderAppointedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("You are the leader of room {$data['room_code']}")
            ->line("{$data['appointed_by']} named you the leader of room {$data['room_code']} at {$data['property_name']}.")
            ->line('As leader you are the one billed for the room\'s shared bills, and you manage the room\'s group.')
            ->action('See my stay', rtrim(config('app.frontend_url'), '/').'/boarder/home');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'You are the room leader',
            'body' => "You now lead room {$data['room_code']} at {$data['property_name']}.",
            'action_url' => '/boarder/home',
        ];
    }
}
