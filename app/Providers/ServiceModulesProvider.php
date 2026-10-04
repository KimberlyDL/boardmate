<?php

namespace App\Providers;

use App\Services\Audit\ActivityLogAuditService;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Files\Contracts\FileService;
use App\Services\Files\LocalFileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Notifications\LaravelNotificationService;
use App\Services\Numbering\Contracts\NumberingService;
use App\Services\Numbering\DatabaseNumberingService;
use App\Services\Scheduler\DailyJobRunner;
use App\Services\SocialAuth\Contracts\GoogleTokenVerifier;
use App\Services\SocialAuth\GoogleIdTokenVerifier;
use Illuminate\Support\ServiceProvider;

/**
 * Binds each shared service module (app/Services/<Module>) to its implementation.
 * Features depend on the module's contract, never on the implementation, so an
 * implementation can be swapped (e.g. local → S3 files) without touching callers.
 *
 * Pure-logic modules (Split, BillingCalendar) need no binding.
 */
class ServiceModulesProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> contract => implementation */
    public array $singletons = [
        FileService::class => LocalFileService::class,
        NotificationService::class => LaravelNotificationService::class,
        NumberingService::class => DatabaseNumberingService::class,
        AuditService::class => ActivityLogAuditService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(GoogleTokenVerifier::class, fn () => new GoogleIdTokenVerifier(config('services.google.client_id')));

        $this->app->bind(DailyJobRunner::class, fn ($app) => new DailyJobRunner(
            array_map(fn (string $class) => $app->make($class), config('boardmate.daily_jobs', [])),
        ));
    }
}
