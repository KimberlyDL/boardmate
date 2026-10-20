<?php

namespace App\Services\Notifications\Templates\HouseOperations;

use App\Models\User;
use App\Services\Notifications\Templates\BaseTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/** Shared shape of the curfew and visitor messages (D2, D4): in-app only, a title and a sentence. */
abstract class HouseOpsTemplate extends BaseTemplate
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
        return ['title' => $this->title($data), 'body' => $this->sentence($data), 'action_url' => $this->url($data)];
    }

    abstract protected function title(array $data): string;

    abstract protected function sentence(array $data): string;

    /** Staff land on the property's tab; tenants on their visitors and curfew page. */
    protected function url(array $data): string
    {
        return ($data['audience'] ?? 'tenant') === 'tenant' ? '/stay/visits' : "/properties/{$data['property_id']}?tab=houseops";
    }
}
