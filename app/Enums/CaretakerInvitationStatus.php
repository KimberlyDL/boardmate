<?php

namespace App\Enums;

enum CaretakerInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for reply',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Revoked => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
