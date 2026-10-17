<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner and caretakers: a boarder gave notice and waits for approval (C1). */
class NoticeFiledTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject("{$data['tenant_name']} gave notice: last day {$data['end_on']}")
            ->line($this->sentence($data));
        if ($data['is_short_notice'] ?? false) {
            $message->line('This is shorter than your minimum notice. It is only a flag; no fee is added.');
        }

        return $message->action('Review the notice', rtrim(config('app.frontend_url'), '/').'/properties/'.$data['property_id']);
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Notice: {$data['tenant_name']}, last day {$data['end_on']}",
            'body' => $this->sentence($data).(($data['is_short_notice'] ?? false) ? ' Short notice.' : ''),
            'action_url' => '/properties/'.$data['property_id'],
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['tenant_name']} (room {$data['room_label']}, {$data['property_name']}) wants to leave. Last day: {$data['end_on']}. Approve it or decline it with a reason.";
    }
}
