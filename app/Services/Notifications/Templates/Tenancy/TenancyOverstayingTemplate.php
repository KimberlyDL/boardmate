<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner and caretakers: someone is still there after their last day (C1, SC-29). In-app only. */
class TenancyOverstayingTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("{$data['tenant_name']} is past their last day")
            ->line($this->sentence($data));
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Past the last day: {$data['tenant_name']}",
            'body' => $this->sentence($data),
            'action_url' => '/properties/'.$data['property_id'],
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['tenant_name']} (room {$data['room_label']}, {$data['property_name']}) was due to leave on {$data['end_on']}. Record the move-out, or extend the end date.";
    }
}
