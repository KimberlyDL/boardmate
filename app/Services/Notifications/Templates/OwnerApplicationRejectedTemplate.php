<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class OwnerApplicationRejectedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject('Your BoardMate owner application')
            ->line('We could not approve your owner application yet.')
            ->line("Reason: {$data['reason']}")
            ->line('You can update your details and apply again from your account.');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Owner application not approved',
            'body' => "Reason: {$data['reason']} You can update your details and apply again.",
            'action_url' => '/owner',
        ];
    }
}
