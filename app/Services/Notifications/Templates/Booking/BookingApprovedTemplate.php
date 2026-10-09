<?php

namespace App\Services\Notifications\Templates\Booking;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: their application was approved (reservation). */
class BookingApprovedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $mail = $this->message($user)
            ->subject("Your booking at {$data['property_name']} is approved")
            ->line("Good news: {$data['unit_label']} at {$data['property_name']} is reserved for you.")
            ->line("Address: {$data['address']}")
            ->line("Please move in by {$data['reserved_until']}. After that the reservation expires and the place is offered to others.")
            ->line('Bring the deposit and first month\'s rent when you move in.');

        if (! empty($data['leader'])) {
            $mail->line('You will also be the leader of your room: the one billed for its shared bills.');
        }

        return $mail->action('See my booking', rtrim(config('app.frontend_url'), '/').'/boarder/bookings');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Booking approved',
            'body' => "{$data['unit_label']} at {$data['property_name']} is reserved for you until {$data['reserved_until']}.",
            'action_url' => '/boarder/bookings',
        ];
    }
}
