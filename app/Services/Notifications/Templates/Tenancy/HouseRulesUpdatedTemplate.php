<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To each current tenant: the place published new house rules to read and accept (D3). */
class HouseRulesUpdatedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject("New house rules at {$data['property_name']}")
            ->line($this->sentence($data));
        if (! empty($data['note'])) {
            $message->line("What changed: {$data['note']}");
        }

        return $message->action('Read the house rules', rtrim(config('app.frontend_url'), '/').'/stay/house-rules');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'New house rules',
            'body' => $this->sentence($data).(! empty($data['note']) ? " What changed: {$data['note']}" : ''),
            'action_url' => '/stay/house-rules',
        ];
    }

    private function sentence(array $data): string
    {
        return "{$data['property_name']} published house rules version {$data['version']}. Please read and accept them.";
    }
}
