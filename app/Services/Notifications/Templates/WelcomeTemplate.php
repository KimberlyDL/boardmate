<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class WelcomeTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject('Welcome to BoardMate');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Welcome to BoardMate',
            'body' => 'Your account is ready. Add your emergency contact in your profile so your boarding house can reach someone if needed.',
            'action_url' => '/profile',
        ];
    }
}
