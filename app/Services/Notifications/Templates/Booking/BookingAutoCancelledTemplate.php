<?php

namespace App\Services\Notifications\Templates\Booking;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: other applications withdrawn because they now hold a reservation. */
class BookingAutoCancelledTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject('Other applications withdrawn');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Other applications withdrawn',
            'body' => "You now have a reservation at {$data['reserved_property']}, so your application for {$data['property_name']} was withdrawn.",
            'action_url' => '/boarder/bookings',
        ];
    }
}
