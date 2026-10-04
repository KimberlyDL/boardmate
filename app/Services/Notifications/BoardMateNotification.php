<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\Templates\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The single Laravel notification class. What it says and which channels it
 * uses come from the event's template.
 */
class BoardMateNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     * @param  User|null  $account  the account the message is about, when it is
     *                              mailed to another address (email change)
     */
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly array $data = [],
        public readonly ?User $account = null,
    ) {}

    public function template(): NotificationTemplate
    {
        return app($this->event->template());
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->template()->channels();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->template()->mail($this->recipientAccount($notifiable), $this->data);
    }

    /** Stored in the `notifications` table for the in-app list. */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event->value] + $this->template()->inApp($this->recipientAccount($notifiable), $this->data);
    }

    /** The account the message is about; null for an address with no account (invitations). */
    private function recipientAccount(object $notifiable): ?User
    {
        return $this->account ?? ($notifiable instanceof User ? $notifiable : null);
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event->value;
    }
}
