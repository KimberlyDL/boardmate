<?php

namespace App\Enums;

/** A line in a room's group (D5). */
enum GroupExpenseKind: string
{
    /** An owner bill the leader received, posted to the group: the amount is locked, only the split changes. */
    case OwnerBill = 'owner_bill';
    /** Something a member paid for the group (e.g. internet they pay themselves, SC-31). */
    case MemberExpense = 'member_expense';
    /** A correction after someone has repaid: signed, never a silent edit. */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::OwnerBill => 'Owner bill',
            self::MemberExpense => 'Shared expense',
            self::Adjustment => 'Correction',
        };
    }
}
