<?php

namespace App\Console\Commands;

use App\Services\HouseOperations\CurfewService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dormlink:curfew-reminders')]
#[Description('Send the in-app reminder before curfew where it is due (runs every 5 minutes; each night once)')]
class SendCurfewReminders extends Command
{
    public function handle(CurfewService $curfew): int
    {
        $sent = $curfew->sendReminders(CarbonImmutable::now('Asia/Manila'));
        $this->components->info("Curfew reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
