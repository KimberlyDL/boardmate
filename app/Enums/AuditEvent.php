<?php

namespace App\Enums;

/**
 * Everything written to the audit log (Features Guide §5: who changed what
 * and when, for prices, bills, payments, approvals, overrides, and caretaker
 * actions). Add cases as features arrive.
 *
 * Scope decides who can read an entry:
 * - owner:   the owner's business log (owner_id set)
 * - admin:   platform-admin actions (admins' log; the affected owner also sees it)
 * - account: personal account security (kept for disputes and support)
 */
enum AuditEvent: string
{
    // Admin
    case OwnerVerified = 'owner.verified';
    case OwnerRejected = 'owner.rejected';
    case OwnerSuspended = 'owner.suspended';
    case OwnerReinstated = 'owner.reinstated';
    case AccountSuspended = 'account.suspended';
    case AccountUnsuspended = 'account.unsuspended';

    // Owner business
    case OwnerApplied = 'owner.applied';
    case OwnerDetailsChanged = 'owner.details_changed';
    case CaretakerInvited = 'caretaker.invited';
    case CaretakerInvitationResent = 'caretaker.invitation_resent';
    case CaretakerInvitationCancelled = 'caretaker.invitation_cancelled';
    case CaretakerInvitationAccepted = 'caretaker.invitation_accepted';
    case CaretakerInvitationDeclined = 'caretaker.invitation_declined';
    case CaretakerAccessChanged = 'caretaker.access_changed';
    case CaretakerRemoved = 'caretaker.removed';

    // Properties (S1, S4)
    case PropertyCreated = 'property.created';
    case PropertyUpdated = 'property.updated';
    case PropertyDeleted = 'property.deleted';
    case PropertyPublished = 'property.published';
    case PropertyUnpublished = 'property.unpublished';
    case RentalModeSwitched = 'property.rental_mode_switched';
    case UnitsAdded = 'unit.added';
    case UnitUpdated = 'unit.updated';
    case UnitRemoved = 'unit.removed';
    case UnitNotReadyChanged = 'unit.not_ready_changed';
    case RentChanged = 'price.rent_changed';
    case UtilityAmountChanged = 'price.utility_changed';
    case UtilityAccountAdded = 'utility.added';
    case UtilityAccountUpdated = 'utility.updated';
    case UtilityAccountRemoved = 'utility.removed';
    case SettingsChanged = 'property.settings_changed';
    case PhotosChanged = 'property.photos_changed';
    case CaretakerAssigned = 'caretaker.assigned';
    case CaretakerUnassigned = 'caretaker.unassigned';

    // Bookings (F2)
    case BookingApproved = 'booking.approved';
    case BookingDeclined = 'booking.declined';
    case BookingCancelled = 'booking.cancelled';
    case ReservationExpired = 'booking.expired';

    // Account security
    case EmailChanged = 'account.email_changed';
    case PasswordChanged = 'account.password_changed';
    case PasswordSet = 'account.password_set';
    case GoogleLinked = 'account.google_linked';

    public function scope(): string
    {
        return match ($this) {
            self::OwnerVerified, self::OwnerRejected, self::OwnerSuspended, self::OwnerReinstated,
            self::AccountSuspended, self::AccountUnsuspended => 'admin',
            self::EmailChanged, self::PasswordChanged, self::PasswordSet, self::GoogleLinked => 'account',
            default => 'owner',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OwnerVerified => 'Verified the owner account',
            self::OwnerRejected => 'Did not approve the owner application',
            self::OwnerSuspended => 'Suspended the owner account',
            self::OwnerReinstated => 'Reinstated the owner account',
            self::AccountSuspended => 'Suspended an account',
            self::AccountUnsuspended => 'Lifted an account suspension',
            self::OwnerApplied => 'Applied to be an owner',
            self::OwnerDetailsChanged => 'Changed business or payment details',
            self::CaretakerInvited => 'Invited a caretaker',
            self::CaretakerInvitationResent => 'Resent a caretaker invitation',
            self::CaretakerInvitationCancelled => 'Cancelled a caretaker invitation',
            self::CaretakerInvitationAccepted => 'Accepted the caretaker invitation',
            self::CaretakerInvitationDeclined => 'Declined the caretaker invitation',
            self::CaretakerAccessChanged => 'Changed a caretaker\'s access',
            self::CaretakerRemoved => 'Removed a caretaker',
            self::PropertyCreated => 'Added a property',
            self::PropertyUpdated => 'Changed property details',
            self::PropertyDeleted => 'Deleted a property',
            self::PropertyPublished => 'Published a listing',
            self::PropertyUnpublished => 'Unpublished a listing',
            self::RentalModeSwitched => 'Switched rental mode',
            self::UnitsAdded => 'Added units',
            self::UnitUpdated => 'Changed a unit',
            self::UnitRemoved => 'Removed a unit',
            self::UnitNotReadyChanged => 'Changed a unit\'s readiness',
            self::RentChanged => 'Changed rent',
            self::UtilityAmountChanged => 'Changed a utility price',
            self::UtilityAccountAdded => 'Added a utility',
            self::UtilityAccountUpdated => 'Changed a utility',
            self::UtilityAccountRemoved => 'Removed a utility',
            self::SettingsChanged => 'Changed property settings',
            self::PhotosChanged => 'Changed listing photos',
            self::CaretakerAssigned => 'Assigned a caretaker to a property',
            self::CaretakerUnassigned => 'Removed a caretaker from a property',
            self::BookingApproved => 'Approved a booking',
            self::BookingDeclined => 'Declined a booking',
            self::BookingCancelled => 'Cancelled a booking',
            self::ReservationExpired => 'A reservation expired',
            self::EmailChanged => 'Changed email',
            self::PasswordChanged => 'Changed password',
            self::PasswordSet => 'Set a password',
            self::GoogleLinked => 'Linked a Google account',
        };
    }
}
