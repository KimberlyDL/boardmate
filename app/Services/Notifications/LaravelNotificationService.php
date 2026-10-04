<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Sends through Laravel notifications (database + mail channels), skipping
 * recipients who muted a mutable event or whose account is suspended.
 *
 * A mail server problem never breaks the action that triggered it (sign-up,
 * a payment confirmation...): the failure is logged and the in-app copy, if
 * the template has one, is still stored.
 */
class LaravelNotificationService implements NotificationService
{
    public function send(User|iterable $recipients, NotificationEvent $event, array $data = []): void
    {
        $recipients = $recipients instanceof User ? [$recipients] : $recipients;

        foreach ($recipients as $user) {
            if ($event->isMutable() && $user->hasMuted($event)) {
                continue;
            }

            // Suspended accounts still get account-security mail, nothing else.
            if ($user->isSuspended() && ! $event->isSecurity()) {
                continue;
            }

            $this->deliver($user, new BoardMateNotification($event, $data));
        }
    }

    public function sendToAddress(string $email, ?User $account, NotificationEvent $event, array $data = []): void
    {
        if ($this->queued()) {
            Notification::route('mail', $email)->notify(new QueuedBoardMateNotification($event, $data, $account));

            return;
        }

        $notification = new BoardMateNotification($event, $data, $account);

        $this->mailSafely($notification, $account, fn () => Notification::route('mail', $email)->notifyNow($notification, ['mail']));
    }

    private function deliver(User $user, BoardMateNotification $notification): void
    {
        if ($this->queued()) {
            // The worker retries failed mail; the in-app copy is queued too.
            $user->notify(new QueuedBoardMateNotification($notification->event, $notification->data, $notification->account));

            return;
        }

        $channels = $notification->via($user);

        // In-app first, so it is stored even if email fails.
        if (in_array('database', $channels, true)) {
            $user->notifyNow($notification, ['database']);
        }

        if (in_array('mail', $channels, true)) {
            $this->mailSafely($notification, $user, fn () => $user->notifyNow($notification, ['mail']));
        }
    }

    private function queued(): bool
    {
        return (bool) config('boardmate.notifications_queue');
    }

    private function mailSafely(BoardMateNotification $notification, ?User $user, callable $send): void
    {
        try {
            $send();
        } catch (TransportExceptionInterface $e) {
            Log::error('Notification email failed', [
                'event' => $notification->event->value,
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
