<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Shared defaults: both channels, greeting by first name, common sign-off.
 */
abstract class BaseTemplate implements NotificationTemplate
{
    public function channels(): array
    {
        return ['mail', 'database'];
    }

    /** $user is null when mailing someone without an account (e.g. an invitation). */
    protected function message(?User $user): MailMessage
    {
        return (new MailMessage)
            ->greeting($user ? 'Hi '.str($user->name)->before(' ').',' : 'Hi,')
            ->salutation("Thanks,\nThe BoardMate team");
    }

    public function inApp(?User $user, array $data): array
    {
        return ['title' => '', 'body' => '', 'action_url' => null];
    }
}
