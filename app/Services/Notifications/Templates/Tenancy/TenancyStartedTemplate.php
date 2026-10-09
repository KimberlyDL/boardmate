<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: they were moved in. */
class TenancyStartedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $mail = $this->message($user)
            ->subject("Welcome to {$data['property_name']}")
            ->line("You are moved in: {$data['unit_label']} at {$data['property_name']}, from {$data['moved_in_on']}.")
            ->line("Your rent is due every month on the {$data['anchor_day']}. Your next rent is due {$data['next_due_on']}.");

        if (! empty($data['is_leader'])) {
            $mail->line('You are also the leader of your room: you are the one billed for its shared bills, and the owner knows to reach you about it.');
        }

        return $mail->action('See my stay', rtrim(config('app.frontend_url'), '/').'/boarder/home');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'You are moved in',
            'body' => "{$data['unit_label']} at {$data['property_name']} from {$data['moved_in_on']}. Next rent due {$data['next_due_on']}.",
            'action_url' => '/boarder/home',
        ];
    }
}
