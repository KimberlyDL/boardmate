<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** To the caretaker: the owner removed them. */
class CaretakerRemovedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject('You are no longer a caretaker for '.$data['owner_name'])
            ->line("{$data['owner_name']} removed you as a caretaker on BoardMate. You can no longer see or manage their properties.");
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Removed as caretaker',
            'body' => "{$data['owner_name']} removed you as a caretaker.",
            'action_url' => null,
        ];
    }
}
