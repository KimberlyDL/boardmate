<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To each tenant who did not mute it: curfew is soon (D2). In-app only. */
class CurfewReminderTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject('Curfew soon')->line($this->sentence($data));
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Curfew at {$data['curfew']}",
            'body' => $this->sentence($data),
            'action_url' => '/stay',
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['property_name']}: the curfew is at {$data['curfew']}, in {$data['minutes']} minutes. Running late? Ask in the app. You can turn these reminders off in My stay.";
    }
}
