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

    /** Store an upload and return its stored path. */
    public function store(UploadedFile $file, FilePurpose $purpose): string;

    public function delete(?string $path, FilePurpose $purpose): void;

    /** Public URL, or a signed expiring link for private files; null when no file. */
    public function url(?string $path, FilePurpose $purpose): ?string;
}
