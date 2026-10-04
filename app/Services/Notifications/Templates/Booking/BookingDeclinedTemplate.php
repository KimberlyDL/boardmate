<?php

namespace App\Services\Notifications\Templates\Booking;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: declined by the owner, or the place is fully booked. */
class BookingDeclinedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $mail = $this->message($user)
            ->subject("Your booking at {$data['property_name']}")
            ->line("Sorry, your application for {$data['property_name']} was not accepted.");

        if (! empty($data['reason'])) {
            $mail->line("Reason: {$data['reason']}");
        }

        return $mail->action('Find another place', rtrim(config('app.frontend_url'), '/').'/find');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Booking declined',
            'body' => "Your application for {$data['property_name']} was not accepted.".(! empty($data['reason']) ? " Reason: {$data['reason']}" : ''),
            'action_url' => '/find',
        ];
    }
}
