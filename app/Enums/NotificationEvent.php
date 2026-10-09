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
        };
    }

    /** @return list<self> */
    public static function mutable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $e) => $e->isMutable()));
    }
}
