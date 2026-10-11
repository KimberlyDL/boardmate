<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: a correction was added to one of their bills (C4). */
class BillAdjustedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Bill {$data['bill_number']} was corrected")
            ->line($this->sentence($data))
            ->line("Reason: {$data['reason']}")
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Bill {$data['bill_number']} corrected",
            'body' => $this->sentence($data),
            'action_url' => '/boarder/bills',
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['property_name']} corrected bill {$data['bill_number']} by {$data['change']}. It now comes to {$data['total']}.";
    }
}
