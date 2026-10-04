<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['mail'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject('Confirm your email for BoardMate')
            ->line('Please confirm that this is your email address.')
            ->action('Confirm email', $data['url'])
            ->line('This link works for 24 hours. If you did not create a BoardMate account, you can ignore this email.');
    }
}
