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

    /** Public files get a permanent URL; private ones only a signed, expiring link. */
    public function isPublic(): bool
    {
        return match ($this) {
            self::ProfilePhoto, self::IdDocument => false,
            self::ListingPhoto => true, // shown on Dorm Finder (F1)
        };
    }

    public function directory(): string
    {
        return match ($this) {
            self::ProfilePhoto => 'profile-photos',
            self::ListingPhoto => 'listing-photos',
            self::IdDocument => 'id-documents',
        };
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::ProfilePhoto, self::ListingPhoto => ['jpg', 'jpeg', 'png', 'webp'],
            self::IdDocument => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        };
    }

    public function maxKilobytes(): int
    {
        return match ($this) {
            self::ProfilePhoto, self::ListingPhoto, self::IdDocument => 5 * 1024,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ProfilePhoto => 'Profile photo',
            self::ListingPhoto => 'Listing photo',
            self::IdDocument => 'ID',
        };
    }
}
