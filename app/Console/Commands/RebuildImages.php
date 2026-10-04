<?php

namespace App\Console\Commands;

use App\Enums\FilePurpose;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Services\Files\Contracts\FileService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('boardmate:rebuild-images')]
#[Description('Convert listing and profile photos uploaded before WebP variants existed (safe to run twice)')]
class RebuildImages extends Command
{
    public function handle(FileService $files): int
    {
        $converted = 0;
        $failed = 0;

        $photos = PropertyPhoto::query()->whereNull('thumb_path')->orderBy('id');
        $this->components->info('Listing photos to convert: '.$photos->count());
        foreach ($photos->lazyById() as $photo) {
            $this->attempt("listing photo #{$photo->id}", function () use ($files, $photo) {
                $variants = $files->rebuildImage($photo->path, FilePurpose::ListingPhoto);
                $photo->forceFill(['path' => $variants['large'], 'thumb_path' => $variants['thumb']])->save();
            }) ? $converted++ : $failed++;
        }

        $users = User::query()->whereNotNull('photo_path')->where('photo_path', 'not like', '%.webp')->orderBy('id');
        $this->components->info('Profile photos to convert: '.$users->count());
        foreach ($users->lazyById() as $user) {
            $this->attempt("profile photo of user #{$user->id}", function () use ($files, $user) {
                $variants = $files->rebuildImage($user->photo_path, FilePurpose::ProfilePhoto);
                $user->forceFill(['photo_path' => $variants['main']])->saveQuietly();
            }) ? $converted++ : $failed++;
        }

        $this->components->info("Converted {$converted}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function attempt(string $what, callable $convert): bool
    {
        try {
            $convert();

            return true;
        } catch (Throwable $e) {
            report($e);
            $this->components->error("{$what}: {$e->getMessage()}");

            return false;
        }
    }
}
