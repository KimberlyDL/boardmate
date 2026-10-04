<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner: their invitation was accepted. */
class CaretakerInvitationAcceptedTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject('Caretaker invitation accepted');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Caretaker joined',
            'body' => "{$data['caretaker_name']} accepted your invitation as a {$data['access_level_label']}.",
            'action_url' => '/owner/caretakers',
        ];
    }
}
