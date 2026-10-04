<?php

namespace App\Enums;

/** Who divides utility shares among members (S3, S6). */
enum SplitManager: string
{
    case Owner = 'owner';
    case LeaseHolder = 'lease_holder';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::LeaseHolder => 'Lease holder',
        };
    }
}
