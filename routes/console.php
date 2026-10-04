<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (Asia/Manila).
| - Development: keep `php artisan schedule:work` running in a terminal.
| - Server: one cron line, see docs/CONVENTIONS.md.
|
| Every feature's daily work runs inside boardmate:daily (config/boardmate.php
| 'daily_jobs'), at the guide's reminder time of 9:00 AM Asia/Manila.
*/

Schedule::command('boardmate:daily')
    ->dailyAt('09:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
