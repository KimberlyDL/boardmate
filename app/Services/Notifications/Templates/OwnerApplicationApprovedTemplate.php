<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class OwnerApplicationApprovedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject('Your BoardMate owner account is approved')
            ->line('Good news: your owner account is verified.')
            ->line('Your listings can now be shown to the public once you publish them.')
            ->action('Open BoardMate', rtrim(config('app.frontend_url'), '/').'/owner');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Owner account approved',
            'body' => 'Your owner account is verified. Your listings can now be published.',
            'action_url' => '/owner',
        ];
    }
}
