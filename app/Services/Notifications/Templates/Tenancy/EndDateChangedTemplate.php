<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: their notice was approved or declined, or staff set, changed or cancelled their last day (C1). */
class EndDateChangedTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject($this->title($data))
            ->line($this->sentence($data));
        if ($data['reason'] ?? null) {
            $message->line("Reason: {$data['reason']}");
        }

        return $message->action('See my stay', rtrim(config('app.frontend_url'), '/').'/stay');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => $this->title($data),
            'body' => $this->sentence($data).(($data['reason'] ?? null) ? " Reason: {$data['reason']}" : ''),
            'action_url' => '/stay',
        ];
    }

    private function title(array $data): string
    {
        return match ($data['change']) {
            'approved' => "Notice approved: last day {$data['end_on']}",
            'declined' => 'Your notice was not approved',
            'cancelled' => 'Your last day was cancelled',
            default => "Your last day: {$data['end_on']}",
        };
    }

    private function sentence(array $data): string
    {
        return match ($data['change']) {
            'approved' => "{$data['property_name']} approved your notice. Your last day of stay is {$data['end_on']}.",
            'declined' => "{$data['property_name']} did not approve your notice to leave on {$data['end_on']}. You stay on, month to month.",
            'cancelled' => "{$data['property_name']} cancelled your last day of stay. You stay on, month to month.",
            default => "{$data['property_name']} set your last day of stay to {$data['end_on']}.",
        };
    }
}
