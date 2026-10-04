<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** Sent to the OLD address when an email change is requested. */
class EmailChangeNoticeTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['mail'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject('Your BoardMate email is being changed')
            ->line("Someone asked to change the email on your BoardMate account to {$data['masked_email']}.")
            ->line('Nothing changes until the new address is confirmed.')
            ->line('If this was not you, log in and change your password right away, then cancel the change in your profile.');
    }
}
