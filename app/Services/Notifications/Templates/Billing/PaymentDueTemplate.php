<?php

namespace App\Services\Notifications\Templates\Billing;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the payer: a payment is due soon, or due today (C7 level 1). */
class PaymentDueTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject($this->title($data).' · '.$data['property_name'])
            ->line($this->sentence($data));
        foreach ($data['bills'] as $bill) {
            $message->line("{$bill['number']}: {$bill['balance']}, due {$bill['due_on']}");
        }

        return $message
            ->line('Already paid? Tell the owner from My bills so they can confirm it.')
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => $this->title($data),
            'body' => $this->sentence($data),
            'action_url' => '/boarder/bills',
        ];
    }

    private function title(array $data): string
    {
        return ($data['kind'] === 'due_today' ? 'Due today: ' : 'Due soon: ').$data['total'];
    }

    private function sentence(array $data): string
    {
        $when = $data['kind'] === 'due_today' ? 'today' : "on {$data['due_on']}";

        return "You have {$data['total']} to pay to {$data['property_name']} {$when}.";
    }
}
