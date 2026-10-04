<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** Emailed to the invitee's address (they may not have an account yet). */
class CaretakerInvitationTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['mail'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("{$data['owner_name']} invited you to be a caretaker on BoardMate")
            ->line("{$data['owner_name']} wants you to help run their boarding house on BoardMate as a {$data['access_level_label']}.")
            ->line($data['access_level_description'])
            ->action('View invitation', $data['url'])
            ->line("The invitation works for {$data['valid_days']} days. Log in or create a BoardMate account with this email address to accept it.");
    }
}
