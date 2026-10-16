<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner and caretakers: a boarder says they paid and waits for confirmation (C6). */
class PaymentReportedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject("{$data['payer_name']} reported a payment of {$data['amount']}")
            ->line($this->sentence($data));

        if ($data['reference_reused'] ?? false) {
            $message->line('Check it carefully: this reference no. was used on another payment before.');
        }

        return $message->action('Review payments', rtrim(config('app.frontend_url'), '/').'/properties/'.$data['property_id']);
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => "Payment to confirm: {$data['amount']}",
            'body' => $this->sentence($data).(($data['reference_reused'] ?? false) ? ' The reference no. was used before.' : ''),
            'action_url' => '/properties/'.$data['property_id'],
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['payer_name']} says they paid {$data['amount']} by {$data['method']} for {$data['property_name']}. Confirm it or reject it with a reason.";
    }
}
