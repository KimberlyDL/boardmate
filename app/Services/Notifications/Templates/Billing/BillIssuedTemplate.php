<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: a new bill was issued (C4). */
class BillIssuedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("New bill from {$data['property_name']}: {$data['total']}")
            ->line("Your bill {$data['bill_number']} for {$data['period']} is {$data['total']}, due {$data['due_on']}.")
            ->line('It has '.($data['summary'] ?? 'your rent for the coming month and the fixed utilities for the month that just ended').'.')
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "New bill: {$data['total']}",
            'body' => "{$data['property_name']} · {$data['bill_number']} for {$data['period']}, due {$data['due_on']}.",
            'action_url' => '/boarder/bills',
        ];
    }
}
