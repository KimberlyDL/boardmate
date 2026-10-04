<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** To platform admins: an owner application is waiting for review. */
class OwnerApplicationSubmittedTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject('New owner application');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'New owner application',
            'body' => "{$data['owner_name']} applied to list properties. Review their application.",
            'action_url' => '/admin/owners',
        ];
    }
}
