<?php

namespace App\Enums;

/**
 * What a caretaker may do for an owner (Billing guide, Caretaker access
 * levels). New caretakers default to Collector; the owner upgrades to Manager.
 */
enum CaretakerAccessLevel: string
{
    case Collector = 'collector';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Collector => 'Collector',
            self::Manager => 'Manager',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Collector => 'Records payments, confirms payment proofs, sends reminders, marks late payers, approves move-out dates, and sees who owes.',
            self::Manager => 'Runs the assigned properties like the owner: prices, rules, bills, bookings, settlements and deposit refunds. Cannot manage caretakers, change your payment details, delete a property or transfer ownership.',
        };
    }
}
