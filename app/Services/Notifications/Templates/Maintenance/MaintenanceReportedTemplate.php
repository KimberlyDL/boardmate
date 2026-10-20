<?php

namespace App\Services\Notifications\Templates\Maintenance;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner and caretakers: a new, normal maintenance request (D1). In-app only. */
class MaintenanceReportedTemplate extends BaseTemplate
{
    public function channels(): array
    {
        return ['database'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)->subject($this->title($data))->line($this->sentence($data));
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => $this->title($data),
            'body' => $this->sentence($data),
            'action_url' => "/properties/{$data['property_id']}?tab=maintenance",
        ];
    }

    protected function title(array $data): string
    {
        return "New repair request: {$data['category']}";
    }

    protected function sentence(array $data): string
    {
        return "{$data['reporter']} reported a problem in {$data['where']} at {$data['property_name']}: {$data['problem']}";
    }
}
