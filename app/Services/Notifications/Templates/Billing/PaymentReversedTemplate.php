<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: a payment recorded for them was reversed as a mistake (C6). */
class PaymentReversedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Payment {$data['receipt_number']} was reversed")
            ->line("{$data['property_name']} reversed the payment of {$data['amount']} (receipt {$data['receipt_number']}) recorded for you.")
            ->line("Reason: {$data['reason']}")
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Payment reversed: {$data['amount']}",
            'body' => "{$data['property_name']} · receipt {$data['receipt_number']}: {$data['reason']}",
            'action_url' => '/boarder/bills',
        ];
    }
}
