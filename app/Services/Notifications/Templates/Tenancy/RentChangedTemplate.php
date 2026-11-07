<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the tenant: the regular rent of the place they stay in changes from a date (improvements Phase 6). */
class RentChangedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject("Your rent at {$data['property_name']} is changing")
            ->line($this->sentence($data))
            ->action('See my stay', rtrim(config('app.frontend_url'), '/').'/boarder/home');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Your rent is changing',
            'body' => $this->sentence($data),
            'action_url' => '/boarder/home',
        ];
    }

    /** `before` may be empty (no rent was set); an agreed rate still applies on top. */
    private function sentence(array $data): string
    {
        $was = ($data['before'] ?? '') !== '' ? " (now {$data['before']})" : '';

        return "{$data['property_name']}, {$data['unit_label']}: the monthly rent becomes {$data['after']}{$was} from {$data['from']}."
            .(($data['has_discount'] ?? false) ? ' Your agreed rate still applies.' : '');
    }
}
