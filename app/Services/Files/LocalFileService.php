<?php

namespace App\Services\Files;

use App\Enums\FilePurpose;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Stores files on the local disks: `public` (storage/app/public, served at
 * /storage) and `local` (storage/app/private, served only by the signed
 * api.v1.files.show route). Swap for an S3 implementation later.
 */
class LocalFileService implements FileService
{
    public function rules(FilePurpose $purpose): array
    {
        return [
            'required',
            'file',
            'mimes:'.implode(',', $purpose->allowedExtensions()),
            'max:'.$purpose->maxKilobytes(),
        ];
    }

    public function store(UploadedFile $file, FilePurpose $purpose): string
    {
        $directory = $purpose->directory().'/'.now()->format('Y/m');

        return $file->store($directory, $this->disk($purpose));
    }

    public function delete(?string $path, FilePurpose $purpose): void
    {
        if ($path) {
            Storage::disk($this->disk($purpose))->delete($path);
        }
    }

    public function url(?string $path, FilePurpose $purpose): ?string
    {
        if (! $path) {
            return null;
        }

        if ($purpose->isPublic()) {
            return Storage::disk('public')->url($path);
        }

        return URL::temporarySignedRoute(
            'api.v1.files.show',
            now()->addMinutes(config('boardmate.private_file_url_minutes')),
            ['path' => $path],
        );
    }

    private function disk(FilePurpose $purpose): string
    {
        return $purpose->isPublic() ? 'public' : 'local';
    }
}
