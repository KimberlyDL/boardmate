<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: the first notice, after the grace days (C7 level 3). No fee is added. */
class BillMarkedLateTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Notice {$data['notice_number']}: bill {$data['bill_number']} is late")
            ->line($this->sentence($data))
            ->line('No fee is added. Please pay or talk to the owner. Already paid? Tell the owner from My bills so they can confirm it.')
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Late: {$data['balance']} (notice {$data['notice_number']})",
            'body' => $this->sentence($data),
            'action_url' => '/boarder/bills',
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['property_name']}: bill {$data['bill_number']} was due {$data['due_on']} and {$data['balance']} is still unpaid after {$data['days_overdue']} days. It is now marked late.";
    }
}
