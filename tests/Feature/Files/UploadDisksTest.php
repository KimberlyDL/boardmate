<?php

use App\Enums\FilePurpose;
use App\Models\User;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Render setup: uploads go to the Supabase Storage disks named in FILES_*_DISK.
beforeEach(function () {
    config([
        'dormlink.files.public_disk' => 'uploads_public',
        'dormlink.files.private_disk' => 'uploads_private',
    ]);
    Storage::fake('uploads_public');
    Storage::fake('uploads_private');
    Storage::fake('public');
    Storage::fake('local');
});

it('stores public photos on the public uploads disk and links to it', function () {
    $files = app(FileService::class);

    $path = $files->store(UploadedFile::fake()->image('room.jpg', 800, 600), FilePurpose::ListingPhoto);

    Storage::disk('uploads_public')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
    expect($files->url($path, FilePurpose::ListingPhoto))->toBe(Storage::disk('uploads_public')->url($path));
});

it('stores private files on the private uploads disk and serves them by signed link', function () {
    $user = User::factory()->boarder()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])
        ->assertOk();

    $path = $user->fresh()->photo_path;
    Storage::disk('uploads_private')->assertExists($path);
    Storage::disk('local')->assertMissing($path);

    $url = $this->getJson('/api/v1/me')->json('data.photo_url');
    $this->get($url)->assertOk();
});
