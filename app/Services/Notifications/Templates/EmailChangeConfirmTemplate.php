<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** Sent to the NEW address during an email change. */
class EmailChangeConfirmTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['mail'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        $hours = config('dormlink.email_link_hours');

        return $this->message($user)
            ->subject('Confirm your new email for DormLink')
            ->line('You asked to use this address for your DormLink account. Confirm it to finish the change.')
            ->action('Confirm new email', $data['url'])
            ->line("This link works for {$hours} hours. Until you confirm, your old email stays on the account.");
    }
}
