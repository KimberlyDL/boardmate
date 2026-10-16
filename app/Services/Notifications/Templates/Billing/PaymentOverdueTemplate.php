<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: a payment is overdue (C7 level 2, within the grace days; or a reminder sent by staff). */
class PaymentOverdueTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject("Overdue: {$data['total']} · {$data['property_name']}")
            ->line($this->sentence($data));
        foreach ($data['bills'] as $bill) {
            $message->line("{$bill['number']}: {$bill['balance']}, was due {$bill['due_on']}");
        }

        return $message
            ->line('Already paid? Tell the owner from My bills so they can confirm it.')
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Overdue: {$data['total']}",
            'body' => $this->sentence($data),
            'action_url' => '/boarder/bills',
        ];
    }

    private function sentence(array $data): string
    {
        $days = $data['days_overdue'] === 1 ? '1 day' : "{$data['days_overdue']} days";

        return "Your payment of {$data['total']} to {$data['property_name']} is {$days} overdue (due {$data['due_on']}). Please pay as soon as you can.";
    }
}
