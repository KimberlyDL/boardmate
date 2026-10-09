<?php

use App\Http\Controllers\Api\V1\Account\ActiveRoleController;
use App\Http\Controllers\Api\V1\Account\EmailChangeController;
use App\Http\Controllers\Api\V1\Account\NotificationController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Admin\OwnerReviewController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Booking\ApplicationController;
use App\Http\Controllers\Api\V1\Booking\MyApplicationController;
use App\Http\Controllers\Api\V1\Caretaker\EmployerController;
use App\Http\Controllers\Api\V1\Caretaker\InvitationController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Owner\BuildingController;
use App\Http\Controllers\Api\V1\Owner\CaretakerController;
use App\Http\Controllers\Api\V1\Owner\OwnerApplicationController;
use App\Http\Controllers\Api\V1\Properties\CaretakerAssignmentController;
use App\Http\Controllers\Api\V1\Properties\PhotoController;
use App\Http\Controllers\Api\V1\Properties\PropertyController;
use App\Http\Controllers\Api\V1\Properties\RoomController;
use App\Http\Controllers\Api\V1\Properties\SettingsController;
use App\Http\Controllers\Api\V1\Properties\UnitController;
use App\Http\Controllers\Api\V1\Properties\UtilityAccountController;
use App\Http\Controllers\Api\V1\Public\ListingController;
use App\Http\Controllers\Api\V1\Tenancies\RoomLeaderController;
use App\Http\Controllers\Api\V1\Tenancies\RoomOccupantController;
use App\Http\Controllers\Api\V1\Tenancies\TenancyController;
use App\Http\Controllers\Api\V1\Tenancies\TenancyDiscountController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

/*
| All endpoints live under /api/v1 (see docs/CONVENTIONS.md).
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // Public auth endpoints, rate limited (see AppServiceProvider).
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', RegisterController::class)->name('register');
            Route::post('login', [SessionController::class, 'store'])->name('login');
            Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
            Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->name('verification.resend');
            Route::post('google', GoogleAuthController::class)->name('google');
        });

        Route::middleware('throttle:20,1')->group(function () {
            Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
                ->whereNumber('id')
                ->name('verification.verify');
            Route::get('email-change/verify/{id}/{hash}', [EmailChangeController::class, 'verify'])
                ->whereNumber('id')
                ->name('email-change.verify');
        });
    });

    // Private files: signed, expiring links from the Files module.
    Route::get('files/{path}', [FileController::class, 'show'])
        ->where('path', '.*')
        ->middleware('signed')
        ->name('files.show');

    Route::middleware(['auth:sanctum', EnsureAccountIsActive::class])->group(function () {
        Route::post('auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');

        Route::prefix('me')->name('me.')->group(function () {
            Route::get('/', [ProfileController::class, 'show'])->name('show');
            Route::patch('/', [ProfileController::class, 'update'])->name('update');
            Route::put('boarder-profile', [ProfileController::class, 'updateBoarderProfile'])->name('boarder-profile.update');
            Route::post('photo', [ProfileController::class, 'storePhoto'])->name('photo.store');
            Route::delete('photo', [ProfileController::class, 'destroyPhoto'])->name('photo.destroy');
            Route::put('password', [ProfileController::class, 'changePassword'])->name('password.update');

            Route::post('email', [EmailChangeController::class, 'store'])->middleware('throttle:6,1')->name('email.store');
            Route::post('email/resend', [EmailChangeController::class, 'resend'])->middleware('throttle:6,1')->name('email.resend');
            Route::delete('email', [EmailChangeController::class, 'destroy'])->name('email.destroy');

            Route::get('notification-preferences', [NotificationController::class, 'preferences'])->name('notification-preferences.show');
            Route::put('notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notification-preferences.update');
        });

        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
            Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
            Route::post('{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id')->name('read');
        });

        Route::patch('me/active-role', ActiveRoleController::class)->name('me.active-role');

        // Anyone signed in can apply to become an owner.
        Route::post('owner/application', [OwnerApplicationController::class, 'store'])->name('owner.application.store');

        // Owner only (managers cannot manage caretakers or payment details).
        Route::middleware('role:owner')->prefix('owner')->name('owner.')->group(function () {
            Route::put('profile', [OwnerApplicationController::class, 'updateProfile'])->name('profile.update');
            Route::get('audit-log', [AuditLogController::class, 'owner'])->name('audit-log');

            Route::get('caretakers', [CaretakerController::class, 'index'])->name('caretakers.index');
            Route::patch('caretakers/{caretaker}', [CaretakerController::class, 'update'])->name('caretakers.update');
            Route::delete('caretakers/{caretaker}', [CaretakerController::class, 'destroy'])->name('caretakers.destroy');
            Route::post('caretaker-invitations', [CaretakerController::class, 'invite'])->middleware('throttle:20,1')->name('invitations.store');
            Route::post('caretaker-invitations/{invitation}/resend', [CaretakerController::class, 'resend'])->middleware('throttle:6,1')->name('invitations.resend');
            Route::delete('caretaker-invitations/{invitation}', [CaretakerController::class, 'revoke'])->name('invitations.destroy');
        });

        Route::middleware('role:owner')->prefix('owner')->name('owner.')->group(function () {
            Route::get('buildings', [BuildingController::class, 'index'])->name('buildings.index');
            Route::post('buildings', [BuildingController::class, 'store'])->name('buildings.store');
            Route::patch('buildings/{building}', [BuildingController::class, 'update'])->name('buildings.update');
            Route::delete('buildings/{building}', [BuildingController::class, 'destroy'])->name('buildings.destroy');
        });

        // Properties: owners and their assigned caretakers. Every action is
        // checked against PropertyAccess for that property.
        Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
        Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
        Route::prefix('properties/{property}')->name('properties.')->group(function () {
            Route::get('/', [PropertyController::class, 'show'])->name('show');
            Route::patch('/', [PropertyController::class, 'update'])->name('update');
            Route::delete('/', [PropertyController::class, 'destroy'])->name('destroy');
            Route::post('publish', [PropertyController::class, 'publish'])->name('publish');
            Route::post('unpublish', [PropertyController::class, 'unpublish'])->name('unpublish');

            Route::get('tenancies', [TenancyController::class, 'index'])->name('tenancies.index');
            Route::get('tenancies/preview', [TenancyController::class, 'preview'])->name('tenancies.preview');
            Route::post('tenancies', [TenancyController::class, 'store'])->name('tenancies.store');

            Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
            Route::post('rooms', [RoomController::class, 'store'])->name('rooms.store');

            Route::get('units', [UnitController::class, 'index'])->name('units.index');

            Route::get('utility-accounts', [UtilityAccountController::class, 'index'])->name('utility-accounts.index');
            Route::post('utility-accounts', [UtilityAccountController::class, 'store'])->name('utility-accounts.store');

            Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');

            Route::post('photos', [PhotoController::class, 'store'])->name('photos.store');
            Route::patch('photos/order', [PhotoController::class, 'order'])->name('photos.order');
            Route::patch('photos/{photo}/cover', [PhotoController::class, 'cover'])->name('photos.cover');
            Route::delete('photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');

            Route::put('caretakers', [CaretakerAssignmentController::class, 'update'])->name('caretakers.update');
        });

        Route::prefix('rooms/{room}')->name('rooms.')->group(function () {
            Route::get('leader', [RoomLeaderController::class, 'show'])->name('leader.show');
            Route::put('leader', [RoomLeaderController::class, 'update'])->name('leader.update');
            Route::get('occupants', [RoomOccupantController::class, 'index'])->name('occupants.index');
            Route::post('occupants', [RoomOccupantController::class, 'store'])->name('occupants.store');
            Route::patch('/', [RoomController::class, 'update'])->name('update');
            Route::delete('/', [RoomController::class, 'destroy'])->name('destroy');
            Route::post('rental-mode', [RoomController::class, 'switchMode'])->name('rental-mode');
            Route::post('bedspaces', [RoomController::class, 'addBedspaces'])->name('bedspaces');
        });

        Route::get('me/tenancies', [TenancyController::class, 'mine'])->name('me.tenancies.index');
        Route::prefix('tenancies/{tenancy}')->name('tenancies.')->group(function () {
            Route::get('/', [TenancyController::class, 'show'])->name('show');
            Route::put('emergency-contact', [TenancyController::class, 'updateEmergencyContact'])->name('emergency-contact.update');
            Route::get('discounts', [TenancyDiscountController::class, 'index'])->name('discounts.index');
            Route::put('discount', [TenancyDiscountController::class, 'update'])->name('discount.update');
            Route::delete('discount', [TenancyDiscountController::class, 'destroy'])->name('discount.destroy');
        });

        Route::prefix('occupants/{occupant}')->name('occupants.')->group(function () {
            Route::patch('/', [RoomOccupantController::class, 'update'])->name('update');
            Route::delete('/', [RoomOccupantController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('units/{unit}')->name('units.')->group(function () {
            Route::patch('/', [UnitController::class, 'update'])->name('update');
            Route::delete('/', [UnitController::class, 'destroy'])->name('destroy');
            Route::patch('not-ready', [UnitController::class, 'notReady'])->name('not-ready');
            Route::put('rent', [UnitController::class, 'setRent'])->name('rent');
            Route::get('price-history', [UnitController::class, 'priceHistory'])->name('price-history');
        });

        Route::prefix('utility-accounts/{account}')->name('utility-accounts.')->group(function () {
            Route::patch('/', [UtilityAccountController::class, 'update'])->name('update');
            Route::delete('/', [UtilityAccountController::class, 'destroy'])->name('destroy');
            Route::put('amount', [UtilityAccountController::class, 'setAmount'])->name('amount');
            Route::get('price-history', [UtilityAccountController::class, 'priceHistory'])->name('price-history');
        });

        // Bookings (F2): the boarder's side…
        Route::post('listings/{property}/applications', [MyApplicationController::class, 'store'])
            ->whereNumber('property')->middleware('throttle:10,1')->name('applications.apply');
        Route::get('me/applications', [MyApplicationController::class, 'index'])->name('me.applications.index');
        Route::post('me/applications/{application}/cancel', [MyApplicationController::class, 'cancel'])->name('me.applications.cancel');

        // …and the owner/Manager side (checked per property).
        Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
        Route::post('applications/{application}/approve', [ApplicationController::class, 'approve'])->name('applications.approve');
        Route::post('applications/{application}/move-in', [TenancyController::class, 'moveIn'])->name('applications.move-in');
        Route::post('applications/{application}/decline', [ApplicationController::class, 'decline'])->name('applications.decline');
        Route::post('applications/{application}/cancel', [ApplicationController::class, 'cancel'])->name('applications.cancel');

        // The invited person (any role; the email must match the invitation).
        Route::post('caretaker-invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
        Route::post('caretaker-invitations/{token}/decline', [InvitationController::class, 'decline'])->name('invitations.decline');

        Route::middleware('role:caretaker')->prefix('caretaker')->name('caretaker.')->group(function () {
            Route::get('owners', [EmployerController::class, 'index'])->name('owners.index');
        });

        Route::middleware('role:platform_admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('owners', [OwnerReviewController::class, 'index'])->name('owners.index');
            Route::post('owners/{user}/verify', [OwnerReviewController::class, 'verify'])->name('owners.verify');
            Route::post('owners/{user}/reject', [OwnerReviewController::class, 'reject'])->name('owners.reject');
            Route::post('owners/{user}/suspend', [OwnerReviewController::class, 'suspend'])->name('owners.suspend');
            Route::post('owners/{user}/reinstate', [OwnerReviewController::class, 'reinstate'])->name('owners.reinstate');

            Route::get('audit-log', [AuditLogController::class, 'admin'])->name('audit-log');
            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
            Route::post('users/{user}/unsuspend', [AdminUserController::class, 'unsuspend'])->name('users.unsuspend');
        });
    });

    // Dorm Finder (F1): public.
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('listings', [ListingController::class, 'index'])->name('listings.index');
        Route::get('listings/{property}', [ListingController::class, 'show'])->whereNumber('property')->name('listings.show');
    });

    // Invitation details for the link in the email (no login needed).
    Route::get('caretaker-invitations/{token}', [InvitationController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('invitations.show');
});
