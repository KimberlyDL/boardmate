<?php

namespace App\Services\Notifications\Templates\Maintenance;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To staff on a request (D1), by `what`: assigned to them, the boarder says
 * it is still broken, the boarder added a note, or withdrew it. In-app only.
 */
class MaintenanceForStaffTemplate extends BaseTemplate
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

    private function title(array $data): string
    {
        return match ($data['what']) {
            'assigned' => "Assigned to you: {$data['category']}",
            'still_broken' => "Still broken: {$data['category']}",
            'withdrawn' => "Request withdrawn: {$data['category']}",
            default => "New note: {$data['category']}",
        };
    }

    private function sentence(array $data): string
    {
        $place = "{$data['where']} at {$data['property_name']}";
        $detail = empty($data['detail']) ? '' : " {$data['detail']}";

        return match ($data['what']) {
            'assigned' => "{$data['by']} assigned you the request in {$place}:{$detail}",
            'still_broken' => "{$data['by']} says the problem in {$place} is still broken:{$detail}",
            'withdrawn' => "{$data['by']} withdrew their request in {$place}.",
            default => "{$data['by']} on the request in {$place}:{$detail}",
        };
    }
}
