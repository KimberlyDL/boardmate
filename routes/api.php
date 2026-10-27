<?php

use App\Http\Controllers\Api\V1\Account\ActiveRoleController;
use App\Http\Controllers\Api\V1\Account\EmailChangeController;
use App\Http\Controllers\Api\V1\Account\NotificationController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Admin\CampusController as AdminCampusController;
use App\Http\Controllers\Api\V1\Admin\OwnerReviewController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Billing\BillController;
use App\Http\Controllers\Api\V1\Billing\ChargeController;
use App\Http\Controllers\Api\V1\Billing\CollectionController;
use App\Http\Controllers\Api\V1\Billing\DocumentController;
use App\Http\Controllers\Api\V1\Billing\PaymentController;
use App\Http\Controllers\Api\V1\Billing\UtilityBillController;
use App\Http\Controllers\Api\V1\Booking\ApplicationController;
use App\Http\Controllers\Api\V1\Booking\MyApplicationController;
use App\Http\Controllers\Api\V1\Caretaker\EmployerController;
use App\Http\Controllers\Api\V1\Caretaker\InvitationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\Groups\GroupController;
use App\Http\Controllers\Api\V1\Groups\GroupExpenseController;
use App\Http\Controllers\Api\V1\Groups\RepaymentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HouseOperations\CurfewController;
use App\Http\Controllers\Api\V1\HouseOperations\LateEntryController;
use App\Http\Controllers\Api\V1\HouseOperations\VisitorController;
use App\Http\Controllers\Api\V1\Maintenance\MaintenanceController;
use App\Http\Controllers\Api\V1\Owner\CaretakerController;
use App\Http\Controllers\Api\V1\Owner\OwnerApplicationController;
use App\Http\Controllers\Api\V1\Properties\CaretakerAssignmentController;
use App\Http\Controllers\Api\V1\Properties\HouseRuleController;
use App\Http\Controllers\Api\V1\Properties\PhotoController;
use App\Http\Controllers\Api\V1\Properties\PropertyController;
use App\Http\Controllers\Api\V1\Properties\RoomController;
use App\Http\Controllers\Api\V1\Properties\SettingsController;
use App\Http\Controllers\Api\V1\Properties\UnitController;
use App\Http\Controllers\Api\V1\Properties\UtilityAccountController;
use App\Http\Controllers\Api\V1\Public\CampusController;
use App\Http\Controllers\Api\V1\Public\ListingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\Tenancies\HouseRuleAcceptanceController;
use App\Http\Controllers\Api\V1\Tenancies\MoveOutController;
use App\Http\Controllers\Api\V1\Tenancies\NoticeController;
use App\Http\Controllers\Api\V1\Tenancies\RoomLeaderController;
use App\Http\Controllers\Api\V1\Tenancies\RoomOccupantController;
use App\Http\Controllers\Api\V1\Tenancies\SettlementController;
use App\Http\Controllers\Api\V1\Tenancies\TenancyController;
use App\Http\Controllers\Api\V1\Tenancies\TenancyDiscountController;
use App\Http\Controllers\Api\V1\TickController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

/*
| All endpoints live under /api/v1 (see docs/CONVENTIONS.md).
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // Outside cron on hosts without one (Render); see TickController.
    Route::post('internal/tick', TickController::class)
        ->middleware('throttle:10,1')
        ->name('internal.tick');

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

    // PDF documents: signed, expiring links from the Documents module.
    Route::middleware(['signed', 'throttle:60,1'])->prefix('documents')->name('documents.')->group(function () {
        Route::get('receipts/{payment}', [DocumentController::class, 'receipt'])->name('receipt');
        Route::get('notices/{bill}', [DocumentController::class, 'notice'])->name('notice');
        Route::get('statements/{property}/{payer}', [DocumentController::class, 'statement'])->whereNumber('property')->name('statement');
        Route::get('settlements/{settlement}', [DocumentController::class, 'settlement'])->name('settlement');
    });

    // CSV reports (A10): a signed, short-lived link from reports/{report}/link.
    Route::get('reports/{report}/download', [ReportController::class, 'download'])
        ->middleware(['signed', 'throttle:30,1'])->where('report', '[a-z_]+')->name('reports.download');

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

            Route::get('maintenance-requests', [MaintenanceController::class, 'mine'])->name('maintenance-requests.index');
            Route::get('maintenance-summary', [MaintenanceController::class, 'summary'])->name('maintenance-summary');
            Route::get('house-operations-summary', [CurfewController::class, 'summary'])->name('house-operations-summary');
            Route::get('dashboard', [DashboardController::class, 'mine'])->name('dashboard');
            Route::get('home', [DashboardController::class, 'home'])->name('home');
            Route::post('maintenance-requests', [MaintenanceController::class, 'report'])->middleware('throttle:20,1')->name('maintenance-requests.store');
        });

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}/link', [ReportController::class, 'link'])->where('report', '[a-z_]+')->name('reports.link');

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
            Route::get('notices', [NoticeController::class, 'pending'])->name('notices.pending');
            Route::post('tenancies', [TenancyController::class, 'store'])->name('tenancies.store');

            Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
            Route::post('rooms', [RoomController::class, 'store'])->name('rooms.store');

            Route::get('units', [UnitController::class, 'index'])->name('units.index');

            Route::get('utility-accounts', [UtilityAccountController::class, 'index'])->name('utility-accounts.index');
            Route::post('utility-accounts', [UtilityAccountController::class, 'store'])->name('utility-accounts.store');
            Route::get('utility-bills', [UtilityBillController::class, 'index'])->name('utility-bills.index');
            Route::post('utility-bills/preview', [UtilityBillController::class, 'preview'])->name('utility-bills.preview');
            Route::post('utility-bills', [UtilityBillController::class, 'store'])->name('utility-bills.store');
            Route::post('charges', [ChargeController::class, 'store'])->name('charges.store');
            Route::get('bills', [BillController::class, 'index'])->name('bills.index');
            Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
            Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
            Route::get('payers/{payer}/balance', [PaymentController::class, 'balance'])->name('payers.balance');
            Route::get('payers/{payer}/statement-link', [DocumentController::class, 'statementLink'])->name('payers.statement-link');
            Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
            Route::get('collections/{payer}/notes', [CollectionController::class, 'notes'])->name('collections.notes.index');
            Route::post('collections/{payer}/notes', [CollectionController::class, 'addNote'])->name('collections.notes.store');
            Route::post('collections/{payer}/remind', [CollectionController::class, 'remind'])->middleware('throttle:30,1')->name('collections.remind');

            Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');

            Route::get('maintenance-requests', [MaintenanceController::class, 'index'])->name('maintenance-requests.index');
            Route::post('maintenance-requests', [MaintenanceController::class, 'log'])->name('maintenance-requests.store');

            Route::get('dashboard', [DashboardController::class, 'property'])->name('dashboard');
            Route::get('late-entries', [LateEntryController::class, 'index'])->name('late-entries.index');
            Route::get('curfew-violations', [CurfewController::class, 'index'])->name('curfew-violations.index');
            Route::get('visitors', [VisitorController::class, 'index'])->name('visitors.index');
            Route::post('visitors', [VisitorController::class, 'walkIn'])->name('visitors.store');
            Route::post('curfew-violations', [CurfewController::class, 'store'])->name('curfew-violations.store');

            Route::get('house-rules', [HouseRuleController::class, 'show'])->name('house-rules.show');
            Route::get('house-rules/versions/{version}', [HouseRuleController::class, 'version'])->whereNumber('version')->name('house-rules.version');
            Route::put('house-rules/draft', [HouseRuleController::class, 'saveDraft'])->name('house-rules.draft.update');
            Route::delete('house-rules/draft', [HouseRuleController::class, 'discardDraft'])->name('house-rules.draft.destroy');
            Route::post('house-rules/publish', [HouseRuleController::class, 'publish'])->name('house-rules.publish');

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
            Route::put('whole-option', [RoomController::class, 'offerWhole'])->name('whole-option');
            Route::get('group', [GroupController::class, 'forRoom'])->name('group.show');
        });

        // Room groups (D5): members see everything; staff see members and their own bills.
        Route::get('me/groups', [GroupController::class, 'mine'])->name('me.groups.index');
        Route::prefix('groups/{group}')->name('groups.')->scopeBindings()->group(function () {
            Route::get('/', [GroupController::class, 'show'])->name('show');
            Route::put('default-split', [GroupController::class, 'setDefaultSplit'])->name('default-split');
            Route::put('members/{member}/role', [GroupController::class, 'setRole'])->name('members.role');
            Route::get('expenses', [GroupExpenseController::class, 'index'])->name('expenses.index');
            Route::put('expenses/{expense}/split', [GroupExpenseController::class, 'split'])->name('expenses.split');
            Route::post('expenses', [GroupExpenseController::class, 'store'])->middleware('throttle:30,1')->name('expenses.store');
            Route::patch('expenses/{expense}', [GroupExpenseController::class, 'update'])->name('expenses.update');
            Route::delete('expenses/{expense}', [GroupExpenseController::class, 'destroy'])->name('expenses.destroy');
            Route::post('expenses/{expense}/corrections', [GroupExpenseController::class, 'correct'])->name('expenses.corrections.store');
            Route::get('balances', [RepaymentController::class, 'balances'])->name('balances');
            Route::get('repayments', [RepaymentController::class, 'index'])->name('repayments.index');
            Route::post('repayments', [RepaymentController::class, 'store'])->middleware('throttle:30,1')->name('repayments.store');
            Route::post('repayments/{repayment}/confirm', [RepaymentController::class, 'confirm'])->name('repayments.confirm');
            Route::post('repayments/{repayment}/reject', [RepaymentController::class, 'reject'])->name('repayments.reject');
        });

        Route::get('me/tenancies', [TenancyController::class, 'mine'])->name('me.tenancies.index');
        // Late-entry requests (D2): the tenant cancels; staff decide.
        Route::prefix('late-entries/{lateEntry}')->name('late-entries.')->group(function () {
            Route::post('cancel', [LateEntryController::class, 'cancel'])->name('cancel');
            Route::post('approve', [LateEntryController::class, 'approve'])->name('approve');
            Route::post('decline', [LateEntryController::class, 'decline'])->name('decline');
        });

        Route::delete('curfew-violations/{violation}', [CurfewController::class, 'destroy'])->name('curfew-violations.destroy');

        // Visitors (D4): the tenant cancels; staff decide overnight stays and check visitors in and out.
        Route::prefix('visitors/{visitor}')->name('visitors.')->group(function () {
            Route::post('cancel', [VisitorController::class, 'cancel'])->name('cancel');
            Route::post('approve', [VisitorController::class, 'approve'])->name('approve');
            Route::post('decline', [VisitorController::class, 'decline'])->name('decline');
            Route::post('check-in', [VisitorController::class, 'checkIn'])->name('check-in');
            Route::post('check-out', [VisitorController::class, 'checkOut'])->name('check-out');
        });

        // Maintenance requests (D1): the reporter or the property's staff.
        Route::prefix('maintenance-requests/{maintenanceRequest}')->name('maintenance-requests.')->group(function () {
            Route::get('/', [MaintenanceController::class, 'show'])->name('show');
            Route::post('start', [MaintenanceController::class, 'start'])->name('start');
            Route::post('resolve', [MaintenanceController::class, 'resolve'])->name('resolve');
            Route::post('cancel', [MaintenanceController::class, 'cancel'])->name('cancel');
            Route::put('assignee', [MaintenanceController::class, 'assign'])->name('assignee.update');
            Route::put('cost', [MaintenanceController::class, 'cost'])->name('cost.update');
            Route::post('notes', [MaintenanceController::class, 'note'])->name('notes.store');
            Route::post('confirm', [MaintenanceController::class, 'confirm'])->name('confirm');
            Route::post('reopen', [MaintenanceController::class, 'reopen'])->name('reopen');
            Route::post('charge', [MaintenanceController::class, 'charge'])->name('charge');
            Route::put('not-ready', [MaintenanceController::class, 'notReady'])->name('not-ready.update');
        });

        Route::prefix('tenancies/{tenancy}')->name('tenancies.')->group(function () {
            Route::get('/', [TenancyController::class, 'show'])->name('show');
            Route::put('emergency-contact', [TenancyController::class, 'updateEmergencyContact'])->name('emergency-contact.update');
            Route::get('house-rules', [HouseRuleAcceptanceController::class, 'show'])->name('house-rules.show');
            Route::get('late-entries', [LateEntryController::class, 'forStay'])->name('late-entries.index');
            Route::post('late-entries', [LateEntryController::class, 'store'])->middleware('throttle:20,1')->name('late-entries.store');
            Route::get('curfew-violations', [CurfewController::class, 'forStay'])->name('curfew-violations.index');
            Route::put('curfew-reminders', [CurfewController::class, 'reminders'])->name('curfew-reminders.update');
            Route::get('visitors', [VisitorController::class, 'forStay'])->name('visitors.index');
            Route::post('visitors', [VisitorController::class, 'store'])->middleware('throttle:30,1')->name('visitors.store');
            Route::post('house-rules/accept', [HouseRuleAcceptanceController::class, 'accept'])->name('house-rules.accept');
            Route::get('discounts', [TenancyDiscountController::class, 'index'])->name('discounts.index');
            Route::put('discount', [TenancyDiscountController::class, 'update'])->name('discount.update');
            Route::delete('discount', [TenancyDiscountController::class, 'destroy'])->name('discount.destroy');

            // Notice and end date (C1): the tenant files or withdraws; staff decide or set.
            Route::get('notices', [NoticeController::class, 'history'])->name('notices.index');
            Route::post('notice', [NoticeController::class, 'file'])->middleware('throttle:10,1')->name('notice.store');
            Route::delete('notice', [NoticeController::class, 'withdraw'])->name('notice.destroy');
            Route::post('notice/approve', [NoticeController::class, 'approve'])->name('notice.approve');
            Route::post('notice/decline', [NoticeController::class, 'decline'])->name('notice.decline');
            Route::put('end-date', [NoticeController::class, 'setEndDate'])->name('end-date.update');
            Route::delete('end-date', [NoticeController::class, 'cancelEndDate'])->name('end-date.destroy');
            Route::post('move-out', [MoveOutController::class, 'store'])->name('move-out.store');
            Route::get('settlement', [SettlementController::class, 'show'])->name('settlement.show');
            Route::post('settlement', [SettlementController::class, 'store'])->name('settlement.store');
            Route::get('settlement/summary-link', [SettlementController::class, 'summaryLink'])->name('settlement.summary-link');
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

        Route::post('utility-bills/{utilityBill}/correct', [UtilityBillController::class, 'correct'])->name('utility-bills.correct');
        Route::post('bills/{bill}/adjustments', [ChargeController::class, 'adjust'])->name('bills.adjustments.store');
        Route::post('bills/{bill}/mark-late', [CollectionController::class, 'markLate'])->name('bills.mark-late');
        Route::get('bills/{bill}/notice-link', [DocumentController::class, 'noticeLink'])->name('bills.notice-link');
        Route::get('payments/{payment}/receipt-link', [DocumentController::class, 'receiptLink'])->name('payments.receipt-link');
        Route::get('me/statement-link', [DocumentController::class, 'myStatementLink'])->name('me.statement-link');
        Route::put('bills/{bill}/review', [CollectionController::class, 'startReview'])->name('bills.review.start');
        Route::delete('bills/{bill}/review', [CollectionController::class, 'endReview'])->name('bills.review.end');

        Route::get('me/bills', [BillController::class, 'mine'])->name('me.bills.index');
        Route::get('me/payments', [PaymentController::class, 'mine'])->name('me.payments.index');
        Route::post('me/payments', [PaymentController::class, 'report'])->middleware('throttle:20,1')->name('me.payments.store');
        Route::prefix('payments/{payment}')->name('payments.')->group(function () {
            Route::post('confirm', [PaymentController::class, 'confirm'])->name('confirm');
            Route::post('reject', [PaymentController::class, 'reject'])->name('reject');
            Route::post('reverse', [PaymentController::class, 'reverse'])->name('reverse');
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

            Route::get('campuses', [AdminCampusController::class, 'index'])->name('campuses.index');
            Route::post('campuses', [AdminCampusController::class, 'store'])->name('campuses.store');
            Route::patch('campuses/{campus}', [AdminCampusController::class, 'update'])->name('campuses.update');
            Route::delete('campuses/{campus}', [AdminCampusController::class, 'destroy'])->name('campuses.destroy');
        });
    });

    // Dorm Finder (F1): public.
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('listings', [ListingController::class, 'index'])->name('listings.index');
        Route::get('campuses', [CampusController::class, 'index'])->name('campuses.index');
        Route::get('listings/{property}', [ListingController::class, 'show'])->whereNumber('property')->name('listings.show');
    });

    // Invitation details for the link in the email (no login needed).
    Route::get('caretaker-invitations/{token}', [InvitationController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('invitations.show');
});
