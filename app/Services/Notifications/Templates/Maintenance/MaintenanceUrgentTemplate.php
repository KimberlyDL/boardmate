<?php

namespace App\Services\Notifications\Templates\Maintenance;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** To the owner and caretakers at once: an urgent maintenance request (D1). In-app and email. */
class MaintenanceUrgentTemplate extends MaintenanceReportedTemplate
{
    public function channels(): array
    {
        return ['database', 'mail'];
    }

    public function mail(?User $user, array $data): MailMessage
    {
        return $this->message($user)
            ->subject($this->title($data))
            ->line($this->sentence($data))
            ->action('See the request', rtrim(config('app.frontend_url'), '/')."/properties/{$data['property_id']}?tab=maintenance");
    }

    protected function title(array $data): string
    {
        return "Urgent repair: {$data['category']} at {$data['property_name']}";
    }
}
