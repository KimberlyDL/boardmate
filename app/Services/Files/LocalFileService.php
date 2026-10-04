<?php

namespace App\Services\Files;

use App\Enums\FilePurpose;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Stores files on the local disks: `public` (storage/app/public, served at
 * /storage) and `local` (storage/app/private, served only by the signed
 * api.v1.files.show route). Swap for an S3 implementation later.
 */
class LocalFileService implements FileService
{
    public function __construct(private readonly ImageVariants $images) {}

    public function rules(FilePurpose $purpose): array
    {
        $rules = [
            'required',
            'file',
            'mimes:'.implode(',', $purpose->allowedExtensions()),
            'max:'.$purpose->maxKilobytes(),
        ];

        if ($purpose->isImage()) {
            $rules[] = 'dimensions:max_width='.ImageVariants::MAX_SIDE.',max_height='.ImageVariants::MAX_SIDE;
        }

        return $rules;
    }

    public function store(UploadedFile $file, FilePurpose $purpose): string
    {
        if ($purpose->isImage()) {
            return array_values($this->storeImage($file, $purpose))[0];
        }

        return $file->store($this->directory($purpose), $this->disk($purpose));
    }

    public function storeImage(UploadedFile $file, FilePurpose $purpose): array
    {
        return $this->saveVariants((string) file_get_contents($file->getRealPath()), $purpose);
    }

    public function rebuildImage(string $path, FilePurpose $purpose): array
    {
        $disk = Storage::disk($this->disk($purpose));
        $paths = $this->saveVariants((string) $disk->get($path), $purpose);

        if (! in_array($path, $paths, true)) {
            $disk->delete($path);
        }

        return $paths;
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

    /** @return array<string, string> */
    private function saveVariants(string $binary, FilePurpose $purpose): array
    {
        $base = $this->directory($purpose).'/'.Str::random(40);
        $paths = [];

        foreach ($this->images->make($binary, $purpose->imageVariants()) as $variant => $webp) {
            $path = "{$base}-{$variant}.webp";
            Storage::disk($this->disk($purpose))->put($path, $webp);
            $paths[$variant] = $path;
        }

        return $paths;
    }

    private function directory(FilePurpose $purpose): string
    {
        return $purpose->directory().'/'.now()->format('Y/m');
    }

    private function disk(FilePurpose $purpose): string
    {
        return $purpose->isPublic() ? 'public' : 'local';
    }
}
