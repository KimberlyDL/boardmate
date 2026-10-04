<?php

use App\Services\Scheduler\Jobs\ExpireReservationsJob;
use App\Services\Scheduler\Jobs\PruneIdleTokensJob;
use App\Services\Scheduler\Jobs\PurgeClosedApplicationIdsJob;

return [

    /*
    | Version of the privacy notice users consent to at sign-up (Data Privacy
    | Act of 2012). Bump it when the notice changes; the version is stored on
    | each user with their consent time.
    */
    'privacy_policy_version' => env('PRIVACY_POLICY_VERSION', '2026-10-03'),

    /*
    | The only seeded account. Set both in .env before `php artisan db:seed`.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'BoardMate Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    | Lifetime of signed links to private files (photos, proofs), in minutes.
    */
    'private_file_url_minutes' => 30,

    /*
    | A login (API token) expires after this many days WITHOUT use. Every
    | request extends it, so people who use the app stay signed in.
    */
    'token_idle_days' => (int) env('SANCTUM_IDLE_DAYS', 30),

    /*
    | Lifetime of email confirmation links (sign-up and email change), in hours.
    */
    'email_link_hours' => 24,

    /*
    | Daily jobs run by `boardmate:daily` at 9:00 AM Asia/Manila, in this order.
    | Each class implements App\Services\Scheduler\Contracts\DailyJob.
    */
    'daily_jobs' => [
        PruneIdleTokensJob::class,
        ExpireReservationsJob::class,
        PurgeClosedApplicationIdsJob::class,
    ],

    /*
    | Send notifications through the database queue instead of immediately.
    | Keep false in development (no worker needed); on the server set true and
    | run `php artisan queue:work`.
    */
    'notifications_queue' => (bool) env('NOTIFICATIONS_QUEUE', false),

];
