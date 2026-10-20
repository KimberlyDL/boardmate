<?php

namespace App\Enums;

use App\Services\Notifications\Templates;

/**
 * Every notification the system can send. Each event maps to exactly one
 * template, so all wording lives in app/Services/Notifications/Templates
 * (Features Guide F9: one consistent voice). Add events as features arrive.
 */
enum NotificationEvent: string
{
    case VerifyEmail = 'verify_email';
    case PasswordReset = 'password_reset';
    case Welcome = 'welcome';
    case EmailChangeConfirm = 'email_change_confirm';
    case EmailChangeNotice = 'email_change_notice';
    case OwnerApplicationSubmitted = 'owner_application_submitted';
    case OwnerApplicationApproved = 'owner_application_approved';
    case OwnerApplicationRejected = 'owner_application_rejected';
    case OwnerSuspended = 'owner_suspended';
    case CaretakerInvitation = 'caretaker_invitation';
    case CaretakerInvitationAccepted = 'caretaker_invitation_accepted';
    case CaretakerAccessChanged = 'caretaker_access_changed';
    case CaretakerRemoved = 'caretaker_removed';
    case BookingReceived = 'booking_received';
    case BookingApproved = 'booking_approved';
    case BookingDeclined = 'booking_declined';
    case BookingAutoCancelled = 'booking_auto_cancelled';
    case BookingCancelled = 'booking_cancelled';
    case ReservationExpiringSoon = 'reservation_expiring_soon';
    case ReservationExpired = 'reservation_expired';
    case TenancyStarted = 'tenancy_started';
    case LeaderAppointed = 'leader_appointed';
    case LeaderEnded = 'leader_ended';
    case OccupantsChanged = 'occupants_changed';
    case ApplicationWithdrawnOnMoveIn = 'application_withdrawn_on_move_in';
    case DiscountChanged = 'discount_changed';
    case BillIssued = 'bill_issued';
    case BillAdjusted = 'bill_adjusted';
    case PaymentReported = 'payment_reported';
    case PaymentConfirmed = 'payment_confirmed';
    case PaymentRejected = 'payment_rejected';
    case PaymentReversed = 'payment_reversed';
    case PaymentDue = 'payment_due';
    case PaymentOverdue = 'payment_overdue';
    case BillMarkedLate = 'bill_marked_late';
    case NoticeFiled = 'notice_filed';
    case EndDateChanged = 'end_date_changed';
    case MovedOut = 'moved_out';
    case TenancyOverstaying = 'tenancy_overstaying';
    case DepositSettled = 'deposit_settled';
    case GroupLinePosted = 'group_line_posted';
    case GroupRepayment = 'group_repayment';
    case HouseRulesUpdated = 'house_rules_updated';
    case MaintenanceReported = 'maintenance_reported';
    case MaintenanceUrgent = 'maintenance_urgent';
    case MaintenanceUpdated = 'maintenance_updated';
    case MaintenanceForStaff = 'maintenance_for_staff';
    case CurfewReminder = 'curfew_reminder';
    case LateEntryRequested = 'late_entry_requested';
    case LateEntryDecided = 'late_entry_decided';
    case OvernightRequested = 'overnight_requested';
    case VisitorDecided = 'visitor_decided';
    case OvernightOverLimit = 'overnight_over_limit';
    case CurfewViolationLogged = 'curfew_violation_logged';

    /** @return class-string<Templates\NotificationTemplate> */
    public function template(): string
    {
        return match ($this) {
            self::VerifyEmail => Templates\VerifyEmailTemplate::class,
            self::PasswordReset => Templates\PasswordResetTemplate::class,
            self::Welcome => Templates\WelcomeTemplate::class,
            self::EmailChangeConfirm => Templates\EmailChangeConfirmTemplate::class,
            self::EmailChangeNotice => Templates\EmailChangeNoticeTemplate::class,
            self::OwnerApplicationSubmitted => Templates\OwnerApplicationSubmittedTemplate::class,
            self::OwnerApplicationApproved => Templates\OwnerApplicationApprovedTemplate::class,
            self::OwnerApplicationRejected => Templates\OwnerApplicationRejectedTemplate::class,
            self::OwnerSuspended => Templates\OwnerSuspendedTemplate::class,
            self::CaretakerInvitation => Templates\CaretakerInvitationTemplate::class,
            self::CaretakerInvitationAccepted => Templates\CaretakerInvitationAcceptedTemplate::class,
            self::CaretakerAccessChanged => Templates\CaretakerAccessChangedTemplate::class,
            self::CaretakerRemoved => Templates\CaretakerRemovedTemplate::class,
            self::BookingReceived => Templates\Booking\BookingReceivedTemplate::class,
            self::BookingApproved => Templates\Booking\BookingApprovedTemplate::class,
            self::BookingDeclined => Templates\Booking\BookingDeclinedTemplate::class,
            self::BookingAutoCancelled => Templates\Booking\BookingAutoCancelledTemplate::class,
            self::BookingCancelled => Templates\Booking\BookingCancelledTemplate::class,
            self::ReservationExpiringSoon => Templates\Booking\ReservationExpiringSoonTemplate::class,
            self::ReservationExpired => Templates\Booking\ReservationExpiredTemplate::class,
            self::TenancyStarted => Templates\Tenancy\TenancyStartedTemplate::class,
            self::LeaderAppointed => Templates\Tenancy\LeaderAppointedTemplate::class,
            self::LeaderEnded => Templates\Tenancy\LeaderEndedTemplate::class,
            self::OccupantsChanged => Templates\Tenancy\OccupantsChangedTemplate::class,
            self::ApplicationWithdrawnOnMoveIn => Templates\Tenancy\ApplicationWithdrawnOnMoveInTemplate::class,
            self::DiscountChanged => Templates\Tenancy\DiscountChangedTemplate::class,
            self::BillIssued => Templates\Billing\BillIssuedTemplate::class,
            self::BillAdjusted => Templates\Billing\BillAdjustedTemplate::class,
            self::PaymentReported => Templates\Billing\PaymentReportedTemplate::class,
            self::PaymentConfirmed => Templates\Billing\PaymentConfirmedTemplate::class,
            self::PaymentRejected => Templates\Billing\PaymentRejectedTemplate::class,
            self::PaymentReversed => Templates\Billing\PaymentReversedTemplate::class,
            self::PaymentDue => Templates\Billing\PaymentDueTemplate::class,
            self::PaymentOverdue => Templates\Billing\PaymentOverdueTemplate::class,
            self::BillMarkedLate => Templates\Billing\BillMarkedLateTemplate::class,
            self::NoticeFiled => Templates\Tenancy\NoticeFiledTemplate::class,
            self::EndDateChanged => Templates\Tenancy\EndDateChangedTemplate::class,
            self::MovedOut => Templates\Tenancy\MovedOutTemplate::class,
            self::TenancyOverstaying => Templates\Tenancy\TenancyOverstayingTemplate::class,
            self::DepositSettled => Templates\Tenancy\DepositSettledTemplate::class,
            self::GroupLinePosted => Templates\Groups\GroupLinePostedTemplate::class,
            self::GroupRepayment => Templates\Groups\GroupRepaymentTemplate::class,
            self::HouseRulesUpdated => Templates\Tenancy\HouseRulesUpdatedTemplate::class,
            self::MaintenanceReported => Templates\Maintenance\MaintenanceReportedTemplate::class,
            self::MaintenanceUrgent => Templates\Maintenance\MaintenanceUrgentTemplate::class,
            self::MaintenanceUpdated => Templates\Maintenance\MaintenanceUpdatedTemplate::class,
            self::MaintenanceForStaff => Templates\Maintenance\MaintenanceForStaffTemplate::class,
            self::CurfewReminder => Templates\Tenancy\CurfewReminderTemplate::class,
            self::LateEntryRequested => Templates\HouseOperations\LateEntryRequestedTemplate::class,
            self::LateEntryDecided => Templates\HouseOperations\LateEntryDecidedTemplate::class,
            self::OvernightRequested => Templates\HouseOperations\OvernightRequestedTemplate::class,
            self::VisitorDecided => Templates\HouseOperations\VisitorDecidedTemplate::class,
            self::OvernightOverLimit => Templates\HouseOperations\OvernightOverLimitTemplate::class,
            self::CurfewViolationLogged => Templates\HouseOperations\CurfewViolationLoggedTemplate::class,
        };
    }

    /** Whether a user may turn this notification off (e.g. curfew reminder). */
    public function isMutable(): bool
    {
        return false;
    }

    /** Account-security mail still reaches suspended accounts. */
    public function isSecurity(): bool
    {
        return in_array($this, [self::PasswordReset, self::EmailChangeNotice], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::VerifyEmail => 'Email verification',
            self::PasswordReset => 'Password reset',
            self::Welcome => 'Welcome',
            self::EmailChangeConfirm => 'Confirm new email',
            self::EmailChangeNotice => 'Email change notice',
            self::OwnerApplicationSubmitted => 'New owner application',
            self::OwnerApplicationApproved => 'Owner account approved',
            self::OwnerApplicationRejected => 'Owner application not approved',
            self::OwnerSuspended => 'Owner account suspended',
            self::CaretakerInvitation => 'Caretaker invitation',
            self::CaretakerInvitationAccepted => 'Caretaker invitation accepted',
            self::CaretakerAccessChanged => 'Caretaker access changed',
            self::CaretakerRemoved => 'Removed as caretaker',
            self::BookingReceived => 'New booking application',
            self::BookingApproved => 'Booking approved',
            self::BookingDeclined => 'Booking declined',
            self::BookingAutoCancelled => 'Booking withdrawn automatically',
            self::BookingCancelled => 'Booking cancelled',
            self::ReservationExpiringSoon => 'Reservation expiring',
            self::ReservationExpired => 'Reservation expired',
            self::TenancyStarted => 'Moved in',
            self::LeaderAppointed => 'Named room leader',
            self::LeaderEnded => 'No longer room leader',
            self::OccupantsChanged => 'Room occupants changed',
            self::ApplicationWithdrawnOnMoveIn => 'Application withdrawn after move-in',
            self::DiscountChanged => 'Agreed rate changed',
            self::BillIssued => 'New bill',
            self::BillAdjusted => 'Bill corrected',
            self::PaymentReported => 'Payment reported',
            self::PaymentConfirmed => 'Payment received',
            self::PaymentRejected => 'Payment not confirmed',
            self::PaymentReversed => 'Payment reversed',
            self::PaymentDue => 'Payment due',
            self::PaymentOverdue => 'Payment overdue',
            self::BillMarkedLate => 'Bill marked late',
            self::NoticeFiled => 'Move-out notice',
            self::EndDateChanged => 'Last day of stay',
            self::MovedOut => 'Moved out',
            self::TenancyOverstaying => 'Stayed past the last day',
            self::DepositSettled => 'Deposit settled',
            self::GroupLinePosted => 'New shared bill in your room',
            self::GroupRepayment => 'Paying back in your room',
            self::HouseRulesUpdated => 'New house rules',
            self::MaintenanceReported => 'New repair request',
            self::MaintenanceUrgent => 'Urgent repair request',
            self::MaintenanceUpdated => 'Update on my repair request',
            self::MaintenanceForStaff => 'Repair request needs you',
            self::CurfewReminder => 'Curfew reminder',
            self::LateEntryRequested => 'Late-entry request',
            self::LateEntryDecided => 'Late-entry decision',
            self::OvernightRequested => 'Overnight visitor to approve',
            self::VisitorDecided => 'Overnight visitor decision',
            self::OvernightOverLimit => 'Over the overnight limit',
            self::CurfewViolationLogged => 'Late arrival recorded',
        };
    }

    /** @return list<self> */
    public static function mutable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $e) => $e->isMutable()));
    }
}
