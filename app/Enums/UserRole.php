<?php

namespace App\Enums;

/**
 * Account roles (spatie/laravel-permission role names). Lease holder is not a
 * role: it is derived from a lease (Billing guide S3).
 */
enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case Owner = 'owner';
    case Caretaker = 'caretaker';
    case Boarder = 'boarder';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform admin',
            self::Owner => 'Owner',
            self::Caretaker => 'Caretaker',
            self::Boarder => 'Boarder',
        };
    }
}
