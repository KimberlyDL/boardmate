<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordResetTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['mail'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        $minutes = config('auth.passwords.users.expire');

        return $this->message($user)
            ->subject('Reset your BoardMate password')
            ->line('We received a request to reset your password.')
            ->action('Reset password', $data['url'])
            ->line("This link works for {$minutes} minutes. If you did not ask for this, you can ignore this email. Your password stays the same.");
    }
}
