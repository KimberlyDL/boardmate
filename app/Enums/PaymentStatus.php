<?php

namespace App\Enums;

/**
 * A recorded payment (C6). Staff-recorded payments start Confirmed; a
 * boarder's report starts Pending until the owner or a caretaker decides.
 * A confirmed payment entered by mistake is Reversed (never deleted).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for confirmation',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Reversed => 'Reversed',
        };
    }
}
