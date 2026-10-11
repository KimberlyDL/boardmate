<?php

namespace App\Services\Notifications\Templates\Booking;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner and the property's Managers: a boarder applied. */
class BookingReceivedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("New booking application for {$data['property_name']}")
            ->line("{$data['boarder_name']} wants to move in on {$data['move_in']}".(! empty($data['room_label']) ? ' and asked for '.(! empty($data['whole']) ? 'the whole of ' : '')."room {$data['room_label']}." : '.'))
            ->action('Review the application', rtrim(config('app.frontend_url'), '/').'/applications');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'New booking application',
            'body' => "{$data['boarder_name']} applied for ".(! empty($data['room_label']) ? (! empty($data['whole']) ? 'the whole of ' : '')."room {$data['room_label']} at " : '')."{$data['property_name']} (move-in {$data['move_in']}).",
            'action_url' => '/applications',
        ];
    }
}
