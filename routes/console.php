<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (Asia/Manila).
| - Development: keep `php artisan schedule:work` running in a terminal.
| - Server: one cron line, see docs/CONVENTIONS.md.
|
| Every feature's daily work runs inside dormlink:daily (config/dormlink.php
| 'daily_jobs'), at the guide's reminder time of 9:00 AM Asia/Manila.
*/

// D2: the reminder before curfew (each property's own time; once a night).
Schedule::command('dormlink:curfew-reminders')
    ->everyFiveMinutes()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

Schedule::command('dormlink:daily')
    ->dailyAt('09:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
