<?php

namespace App\Services\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Same notification, sent by the queue worker. Used only when
 * NOTIFICATIONS_QUEUE=true (server), so development needs no worker.
 */
class QueuedBoardMateNotification extends BoardMateNotification implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> seconds between retries (e.g. mail server hiccups) */
    public array $backoff = [60, 300];
}
