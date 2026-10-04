<?php

namespace App\Services\Notifications\Templates\Booking;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder and the owner: no move-in, the unit is available again. */
class ReservationExpiredTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Reservation expired: {$data['property_name']}")
            ->line("The reservation for {$data['unit_label']} at {$data['property_name']} ({$data['boarder_name']}) expired without a move-in.")
            ->line('The unit is available again.');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Reservation expired',
            'body' => "{$data['unit_label']} at {$data['property_name']} ({$data['boarder_name']}) was not moved into in time and is available again.",
            'action_url' => null,
        ];
    }
}
