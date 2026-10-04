<?php

namespace App\Services\Files\Contracts;

use App\Enums\FilePurpose;
use Illuminate\Http\UploadedFile;

/**
 * Files module: the one way features store and link to uploaded files.
 */
interface FileService
{
    /** Validation rules for an upload of this purpose (type and size limits). */
    public function rules(FilePurpose $purpose): array;

    /** Store an upload and return its stored path. Photo purposes are stored as their main variant. */
    public function store(UploadedFile $file, FilePurpose $purpose): string;

    /**
     * Store a photo as its WebP variants (FilePurpose::imageVariants()).
     *
     * @return array<string, string> variant => stored path
     */
    public function storeImage(UploadedFile $file, FilePurpose $purpose): array;

    /**
     * Re-create the variants of a photo already stored (e.g. uploaded before
     * variants existed). The old file is deleted once the new ones are saved.
     *
     * @return array<string, string> variant => stored path
     */
    public function rebuildImage(string $path, FilePurpose $purpose): array;

    public function delete(?string $path, FilePurpose $purpose): void;

    /** Public URL, or a signed expiring link for private files; null when no file. */
    public function url(?string $path, FilePurpose $purpose): ?string;
}
