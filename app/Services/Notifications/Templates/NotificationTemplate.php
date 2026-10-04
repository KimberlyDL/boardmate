<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * One template per NotificationEvent. Wording rules: plain English, short
 * sentences, say what happened and what to do next.
 */
interface NotificationTemplate
{
    /** @return list<'mail'|'database'> */
    public function channels(): array;

    /** @param  array<string, mixed>  $data */
    public function mail(?User $user, array $data): MailMessage;

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, body: string, action_url?: string|null}
     */
    public function inApp(?User $user, array $data): array;
}
