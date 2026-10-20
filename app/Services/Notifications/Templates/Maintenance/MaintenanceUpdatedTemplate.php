<?php

namespace App\Services\Notifications\Templates\Maintenance;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the one who reported it: staff started work, marked it as fixed,
 * cancelled it, or added a note (`what`). In-app only.
 */
class MaintenanceUpdatedTemplate extends BaseTemplate
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
            'action_url' => '/stay/maintenance',
        ];
    }

    private function title(array $data): string
    {
        return match ($data['what']) {
            'started' => "Work started: {$data['category']}",
            'resolved' => "Fixed? {$data['category']}",
            'cancelled' => "Request cancelled: {$data['category']}",
            default => "Update on your request: {$data['category']}",
        };
    }

    private function sentence(array $data): string
    {
        $detail = empty($data['detail']) ? '' : " {$data['detail']}";

        return match ($data['what']) {
            'started' => "{$data['by']} started on your request at {$data['property_name']}.",
            'resolved' => "{$data['by']} marked your request as fixed.{$detail} Please confirm, or say it is still broken. It closes by itself in 3 days.",
            'cancelled' => "{$data['by']} cancelled your request.{$detail}",
            default => "{$data['by']}:{$detail}",
        };
    }
}
