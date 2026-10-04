<?php

namespace App\Enums;

/**
 * Owner account review by the platform admin (Platform rule: owners are
 * admin-verified before their listings go public).
 */
enum OwnerVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Under review',
            self::Verified => 'Verified',
            self::Rejected => 'Not approved',
            self::Suspended => 'Suspended',
        };
    }
}
