<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** To the caretaker: the owner changed their access level. */
class CaretakerAccessChangedTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject('Your caretaker access changed');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Caretaker access changed',
            'body' => "{$data['owner_name']} changed your access to {$data['access_level_label']}.",
            'action_url' => '/caretaker',
        ];
    }
}
