<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: a payment was recorded or confirmed, with its receipt number (C6). */
class PaymentConfirmedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Payment received: {$data['amount']} (receipt {$data['receipt_number']})")
            ->line("{$data['property_name']} received your payment of {$data['amount']} made on {$data['paid_on']}. Receipt no. {$data['receipt_number']}.")
            ->line($this->standing($data))
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Payment received: {$data['amount']}",
            'body' => "{$data['property_name']} · receipt {$data['receipt_number']}. ".$this->standing($data),
            'action_url' => '/boarder/bills',
        ];
    }

    private function standing(array $data): string
    {
        if ($data['credit'] ?? null) {
            return "You have {$data['credit']} credit for your next bills.";
        }

        return ($data['owed'] ?? null) ? "You still owe {$data['owed']}." : 'Your bills are fully paid.';
    }
}
