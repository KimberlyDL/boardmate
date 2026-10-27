<?php

use App\Services\Scheduler\Jobs\CloseResolvedMaintenanceJob;
use App\Services\Scheduler\Jobs\ExpireReservationsJob;
use App\Services\Scheduler\Jobs\FlagOverstaysJob;
use App\Services\Scheduler\Jobs\IssueRentBillsJob;
use App\Services\Scheduler\Jobs\PruneIdleTokensJob;
use App\Services\Scheduler\Jobs\PurgeClosedApplicationIdsJob;
use App\Services\Scheduler\Jobs\PurgeOldVisitorsJob;
use App\Services\Scheduler\Jobs\PurgeOwnerDocumentsJob;
use App\Services\Scheduler\Jobs\SendPaymentRemindersJob;

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
        'name' => env('ADMIN_NAME', 'DormLink Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    | Lifetime of signed links to private files (photos, proofs), in minutes.
    */
    'private_file_url_minutes' => 30,

    /*
    | Where uploads are stored (disks in config/filesystems.php). Locally the
    | public and local disks; on Render uploads_public and uploads_private
    | (Supabase Storage), because Render's own disk is wiped on every deploy.
    */
    'files' => [
        'public_disk' => env('FILES_PUBLIC_DISK', 'public'),
        'private_disk' => env('FILES_PRIVATE_DISK', 'local'),
    ],

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
    | Daily jobs run by `dormlink:daily` at 9:00 AM Asia/Manila, in this order.
    | Each class implements App\Services\Scheduler\Contracts\DailyJob.
    */
    'daily_jobs' => [
        PruneIdleTokensJob::class,
        ExpireReservationsJob::class,
        PurgeClosedApplicationIdsJob::class,
        PurgeOwnerDocumentsJob::class,
        IssueRentBillsJob::class,
        SendPaymentRemindersJob::class, // after the day's bills are issued
        FlagOverstaysJob::class,
        CloseResolvedMaintenanceJob::class,
        PurgeOldVisitorsJob::class,
    ],

    /*
    | Send notifications through the database queue instead of immediately.
    | Keep false in development (no worker needed); on the server set true and
    | run `php artisan queue:work`.
    */
    'notifications_queue' => (bool) env('NOTIFICATIONS_QUEUE', false),

    /*
    | Secret for POST /api/v1/internal/tick, which an outside cron service
    | (e.g. cron-job.org) calls every 5 minutes on hosts without cron. Sent in
    | the X-Cron-Secret header. Empty = the endpoint is off (404).
    */
    'cron_secret' => env('CRON_SECRET'),

];
