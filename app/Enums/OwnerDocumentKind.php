<?php

namespace App\Enums;

/**
 * Proof attached to an owner application. A valid ID and one proof of the
 * property are required; the permit is optional.
 */
enum OwnerDocumentKind: string
{
    case GovernmentId = 'government_id';
    case PropertyProof = 'property_proof';
    case BusinessPermit = 'business_permit';
    case Other = 'other';

    /** @return list<self> */
    public static function required(): array
    {
        return [self::GovernmentId, self::PropertyProof];
    }

    public function label(): string
    {
        return match ($this) {
            self::GovernmentId => 'Valid government ID',
            self::PropertyProof => 'Proof of the property (title, lease, or a utility bill in your name)',
            self::BusinessPermit => 'Business or barangay permit',
            self::Other => 'Other document',
        };
    }
}
