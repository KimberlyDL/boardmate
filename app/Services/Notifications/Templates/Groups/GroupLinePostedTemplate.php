<?php

namespace App\Services\Notifications\Templates\Groups;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To a room-group member: their share of a new shared line (D5). In-app only. */
class GroupLinePostedTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Your share: {$data['share']}")
            ->line($this->sentence($data));
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Your share: {$data['share']}",
            'body' => $this->sentence($data),
            'action_url' => '/groups/'.$data['group_id'],
        ];
    }

    private function sentence(array $data): string
    {
        return "Room {$data['room_label']}: your share of {$data['description']} is {$data['share']}, owed to {$data['owed_to']}.";
    }
}
