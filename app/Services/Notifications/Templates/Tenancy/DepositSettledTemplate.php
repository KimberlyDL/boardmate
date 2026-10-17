<?php

namespace App\Services\Notifications\Templates\Tenancy;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** To the boarder: how their deposit was settled after move-out (C8). */
class DepositSettledTemplate extends BaseTemplate
{
    public function mail(?User $user, array $data): MailMessage
    {
        $message = $this->message($user)
            ->subject("Your deposit at {$data['property_name']} is settled")
            ->line("Deposit held: {$data['deposit']}.");
        foreach ($data['deductions'] ?? [] as $line) {
            $message->line("Deducted: {$line}");
        }
        $message->line("Used for unpaid bills: {$data['applied']}.");
        if ($data['kept'] ?? null) {
            $message->line("Kept by the owner: {$data['kept']} ({$data['kept_reason']}).");
        }
        $message->line($this->outcome($data));

        return $message->action('See the summary', rtrim(config('app.frontend_url'), '/').'/stay');
    }

    public function inApp(?User $user, array $data): array
    {
        return [
            'title' => 'Deposit settled',
            'body' => "{$data['property_name']}: deposit {$data['deposit']}, {$data['applied']} used for bills. ".$this->outcome($data),
            'action_url' => '/stay',
        ];
    }

    private function outcome(array $data): string
    {
        $parts = [];
        if ($data['refund'] ?? null) {
            $parts[] = "{$data['refund']} is returned to you.";
        }
        if ($data['still_owed'] ?? null) {
            $parts[] = "You still owe {$data['still_owed']}.";
        }

        return $parts ? implode(' ', $parts) : 'Nothing is owed either way.';
    }
}
