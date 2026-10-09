<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the tenant: their agreed rate (discount) was set, changed or ended. */
class DiscountChangedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Your agreed rate at {$data['property_name']} changed")
            ->line($this->sentence($data))
            ->action('See my stay', rtrim(config('app.frontend_url'), '/').'/boarder/home');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Your agreed rate changed',
            'body' => $this->sentence($data),
            'action_url' => '/boarder/home',
        ];
    }

    /** `change` is "set" or "ended"; `description` reads like "₱500.00 off your rent". */
    private function sentence(array $data): string
    {
        return $data['change'] === 'ended'
            ? "{$data['property_name']}: your agreed rate ends from {$data['from']}. Your rent goes back to the regular price."
            : "{$data['property_name']}: from {$data['from']} you get {$data['description']}.";
    }
}
