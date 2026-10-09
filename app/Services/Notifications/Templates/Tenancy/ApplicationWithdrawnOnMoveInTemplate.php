<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: another application was withdrawn because they moved in somewhere. */
class ApplicationWithdrawnOnMoveInTemplate extends BaseTemplate
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
            'body' => "You moved into {$data['moved_property']}, so your application for {$data['property_name']} was withdrawn.",
            'action_url' => '/boarder/bookings',
        ];
    }
}
