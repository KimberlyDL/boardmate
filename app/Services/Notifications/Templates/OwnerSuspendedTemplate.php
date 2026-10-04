<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class OwnerSuspendedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject('Your BoardMate owner account is suspended')
            ->line('Your owner account has been suspended, so your listings are hidden from the public.')
            ->line("Reason: {$data['reason']}")
            ->line('Reply to this email if you think this is a mistake.');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Owner account suspended',
            'body' => "Your listings are hidden. Reason: {$data['reason']}",
            'action_url' => '/owner',
        ];
    }
}
