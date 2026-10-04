<?php

namespace App\Enums;

/**
 * Everything someone can do on a property, checked on every action
 * (Features Guide §5, Roles and permissions). Who may do what lives in
 * App\Authorization\PropertyAccess; the Billing guide's "Roles and
 * permissions" and "Caretaker access levels" tables are the source.
 */
enum PropertyAbility: string
{
    // Collector and up (Billing guide: Collector "Can do")
    case View = 'view';
    case RecordPayments = 'record_payments';
    case ConfirmPaymentProofs = 'confirm_payment_proofs';
    case SendReminders = 'send_reminders';
    case MarkLate = 'mark_late';
    case ApproveMoveOut = 'approve_move_out';
    case ViewArrears = 'view_arrears';

    // Manager and owner
    case EditDetails = 'edit_details';
    case ManagePrices = 'manage_prices';
    case ManageRules = 'manage_rules';
    case ManageUnits = 'manage_units';
    case ManageUtilityBills = 'manage_utility_bills';
    case EditIssuedBills = 'edit_issued_bills';
    case ApproveBookings = 'approve_bookings';
    case ManageTenancies = 'manage_tenancies';
    case ManageSettlements = 'manage_settlements';
    case RefundDeposits = 'refund_deposits';
    case PublishListing = 'publish_listing';
    case ViewCollections = 'view_collections';

    // Owner only (Billing guide: Manager "Cannot do")
    case ManageCaretakers = 'manage_caretakers';
    case EditOwnerPaymentDetails = 'edit_owner_payment_details';
    case DeleteProperty = 'delete_property';
    case TransferOwnership = 'transfer_ownership';
    case ViewAuditLog = 'view_audit_log';
}
