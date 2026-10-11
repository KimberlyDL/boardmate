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
            ->line("Good news: {$this->place($data)} at {$data['property_name']} is reserved for you.");

        if (! empty($data['requested_room'])) {
            $mail->line("The owner placed you in room {$data['room_label']} instead of room {$data['requested_room']}, which you asked for.");
        }

        $mail->line("Address: {$data['address']}")
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
            'body' => "{$this->place($data)} at {$data['property_name']} is reserved for you until {$data['reserved_until']}."
                .(! empty($data['requested_room']) ? " You were placed in room {$data['room_label']} instead of room {$data['requested_room']}." : ''),
            'action_url' => '/boarder/bookings',
        ];
    }

    /** "Bed 2 in room 201", or the unit alone when the room is not known (older messages). */
    private function place(array $data): string
    {
        return empty($data['room_label']) || $data['room_label'] === $data['unit_label']
            ? $data['unit_label']
            : "{$data['unit_label']} in room {$data['room_label']}";
    }
}
