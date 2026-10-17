<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: their move-out was recorded; the deposit is settled next (C1, C8). */
class MovedOutTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject("Move-out recorded: {$data['property_name']}")
            ->line($this->sentence($data));
        if ($data['final_bill'] ?? null) {
            $message->line("Your last month's fixed utilities come to {$data['final_bill']} on a final bill.");
        }

        return $message->line('The owner settles your deposit next: unpaid bills are paid from it and the rest is returned to you.')
            ->action('See my bills', rtrim(config('app.frontend_url'), '/').'/boarder/bills');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Move-out recorded',
            'body' => $this->sentence($data).(($data['final_bill'] ?? null) ? " Final bill: {$data['final_bill']}." : ''),
            'action_url' => '/boarder/bills',
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['property_name']} recorded that you moved out on {$data['moved_out_on']}. Thank you for staying.";
    }
}
