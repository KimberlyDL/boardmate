<?php

namespace App\Services\Notifications\Contracts;

use App\Enums\NotificationEvent;
use App\Models\User;

/**
 * Notifications module: the one service every feature calls to tell people
 * something (in-app list and email; push after the Android build).
 */
interface NotificationService
{
    /**
     * @param  User|iterable<User>  $recipients
     * @param  array<string, mixed>  $data  values the event's template needs
     */
    public function send(User|iterable $recipients, NotificationEvent $event, array $data = []): void;

    /**
     * Email an address that is not (yet) the account's own, e.g. the new
     * address during an email change. The template still receives $account.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToAddress(string $email, ?User $account, NotificationEvent $event, array $data = []): void;
}
