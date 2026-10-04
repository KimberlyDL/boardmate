<?php

namespace App\Services\Notifications\Templates\Booking;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder, the day before their reservation's last day. */
class ReservationExpiringSoonTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Your reservation at {$data['property_name']} ends tomorrow")
            ->line("{$data['unit_label']} at {$data['property_name']} is held for you until {$data['reserved_until']}.")
            ->line('If you are not moving in, please cancel so someone else can have it.')
            ->action('See my booking', rtrim(config('app.frontend_url'), '/').'/boarder/bookings');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Reservation ends tomorrow',
            'body' => "{$data['unit_label']} at {$data['property_name']} is held for you until {$data['reserved_until']}.",
            'action_url' => '/boarder/bookings',
        ];
    }
}
