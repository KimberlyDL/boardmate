<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: their reported payment was not confirmed; the charges stay unpaid (C6). */
class PaymentRejectedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Your payment of {$data['amount']} was not confirmed")
            ->line("{$data['property_name']} could not confirm the payment of {$data['amount']} you reported for {$data['paid_on']}.")
            ->line("Reason: {$data['reason']}")
            ->line('Your bills stay unpaid. Talk to the owner or report the payment again with the right details.')
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Payment not confirmed: {$data['amount']}",
            'body' => "{$data['property_name']}: {$data['reason']}",
            'action_url' => '/boarder/bills',
        ];
    }
}
