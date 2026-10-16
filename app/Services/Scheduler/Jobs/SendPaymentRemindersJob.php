<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Billing\ReminderService;
use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;

/** C7: due-soon, due-today and overdue reminders, and late marking with a first notice. Runs after the day's bills are issued. */
class SendPaymentRemindersJob implements DailyJob
{
    public function __construct(private readonly ReminderService $reminders) {}

    public function key(): string
    {
        return 'billing.send_payment_reminders';
    }

    public function run(CarbonImmutable $day): string
    {
        $r = $this->reminders->run($day);

        return "Reminded {$r['reminded']} payer(s); marked {$r['marked_late']} bill(s) late.";
    }
}
