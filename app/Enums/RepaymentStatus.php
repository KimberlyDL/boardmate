<?php

namespace App\Enums;

/** A repayment between group members (D5): marked paid, then confirmed or rejected. */
enum RepaymentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for confirmation',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Not confirmed',
        };
    }
}
