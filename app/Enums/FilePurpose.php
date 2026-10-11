<?php

namespace App\Enums;

/**
 * Why a file is uploaded. Each purpose fixes its storage visibility, folder,
 * allowed types and size limit (Features Guide §5, File uploads). Add a case
 * when a feature needs a new kind of upload (listing photo, payment proof...).
 */
enum FilePurpose: string
{
    case ProfilePhoto = 'profile_photo';
    case ListingPhoto = 'listing_photo';
    case IdDocument = 'id_document';
    case OwnerDocument = 'owner_document';
    case PaymentProof = 'payment_proof';
    case UtilityBillPhoto = 'utility_bill_photo';

    /** Public files get a permanent URL; private ones only a signed, expiring link. */
    public function isPublic(): bool
    {
        return match ($this) {
            self::ProfilePhoto, self::IdDocument, self::OwnerDocument, self::PaymentProof, self::UtilityBillPhoto => false,
            self::ListingPhoto => true, // shown on Dorm Finder (F1)
        };
    }

    public function directory(): string
    {
        return match ($this) {
            self::ProfilePhoto => 'profile-photos',
            self::ListingPhoto => 'listing-photos',
            self::IdDocument => 'id-documents',
            self::OwnerDocument => 'owner-documents',
            self::PaymentProof => 'payment-proofs',
            self::UtilityBillPhoto => 'utility-bills',
        };
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::ProfilePhoto, self::ListingPhoto => ['jpg', 'jpeg', 'png', 'webp'],
            self::IdDocument, self::OwnerDocument, self::PaymentProof, self::UtilityBillPhoto => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        };
    }

    /**
     * Photos are re-encoded as WebP on upload (smaller, and EXIF/GPS is
     * dropped); the original is not kept. The first variant is the main path.
     *
     * @return array<string, array{fit: 'inside'|'square', size: int}>
     */
    public function imageVariants(): array
    {
        return match ($this) {
            self::ListingPhoto => ['large' => ['fit' => 'inside', 'size' => 1600], 'thumb' => ['fit' => 'inside', 'size' => 480]],
            self::ProfilePhoto => ['main' => ['fit' => 'square', 'size' => 256]],
            // Kept as sent, so the admin, owner or caretaker can read them.
            self::IdDocument, self::OwnerDocument, self::PaymentProof, self::UtilityBillPhoto => [],
        };
    }

    public function isImage(): bool
    {
        return $this->imageVariants() !== [];
    }

    public function maxKilobytes(): int
    {
        return match ($this) {
            self::ProfilePhoto, self::ListingPhoto, self::IdDocument, self::OwnerDocument, self::PaymentProof, self::UtilityBillPhoto => 5 * 1024,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ProfilePhoto => 'Profile photo',
            self::ListingPhoto => 'Listing photo',
            self::IdDocument => 'ID',
            self::OwnerDocument => 'Owner document',
            self::PaymentProof => 'Proof of payment',
            self::UtilityBillPhoto => 'Utility bill photo',
        };
    }
}
