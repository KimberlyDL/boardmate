<?php

namespace App\Services\Notifications\Templates\Booking;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the other side when a boarder withdraws or the owner cancels a reservation. */
class BookingCancelledTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $mail = $this->message($user)
            ->subject("Booking cancelled: {$data['property_name']}")
            ->line("{$data['cancelled_by']} cancelled the booking for {$data['unit_label']} at {$data['property_name']}.");

        if (! empty($data['reason'])) {
            $mail->line("Reason: {$data['reason']}");
        }

        return $mail;
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Booking cancelled',
            'body' => "{$data['cancelled_by']} cancelled the booking for {$data['unit_label']} at {$data['property_name']}."
                .(! empty($data['reason']) ? " Reason: {$data['reason']}" : ''),
            'action_url' => $data['action_url'] ?? null,
        ];
    }
}
