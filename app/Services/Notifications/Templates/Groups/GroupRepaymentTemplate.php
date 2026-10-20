<?php

namespace App\Services\Notifications\Templates\Groups;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** Room-group repayments (D5): to the one paid when marked, to the payer when confirmed or rejected. In-app only. */
class GroupRepaymentTemplate extends BaseTemplate
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
            'action_url' => '/groups/'.$data['group_id'],
        ];
    }

    private function title(array $data): string
    {
        return match ($data['what']) {
            'marked' => "{$data['from']} paid you {$data['amount']}?",
            'confirmed' => "Repayment confirmed: {$data['amount']}",
            default => "Repayment not confirmed: {$data['amount']}",
        };
    }

    private function sentence(array $data): string
    {
        return match ($data['what']) {
            'marked' => "Room {$data['room_label']}: {$data['from']} says they paid you {$data['amount']}. Confirm it if you got it.",
            'confirmed' => "Room {$data['room_label']}: {$data['to']} confirmed your {$data['amount']}.",
            default => "Room {$data['room_label']}: {$data['to']} did not confirm your {$data['amount']}".(($data['reason'] ?? null) ? ": {$data['reason']}" : '.'),
        };
    }
}
