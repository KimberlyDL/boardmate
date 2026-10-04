<?php

use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Photos are re-encoded to WebP on upload: listing photos as a 1600 px
| `large` and a 480 px `thumb`, profile photos as a 256 px square. The
| original (with its EXIF/GPS data) is not kept.
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
});

/** A real JPEG with an EXIF block carrying a GPS-like marker. */
function jpegWithExif(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    $payload = "Exif\0\0".'MM'."\0*\0\0\0\x08".'GPS-14.5995N-120.9842E-SECRET';
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

    return new UploadedFile($path, 'beach.jpg', 'image/jpeg', null, true);
}

it('saves listing photos as small WebP large and thumb versions without EXIF', function () {
    $upload = jpegWithExif(3000, 2000);
    expect(file_get_contents($upload->getRealPath()))->toContain('SECRET');

    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/photos", ['photos' => [$upload]])
        ->assertCreated()
        ->assertJsonPath('data.photos.0.thumb_url', fn ($url) => str_ends_with($url, '-thumb.webp'));

    $photo = PropertyPhoto::first();
    $disk = Storage::disk('public');

    foreach ([[$photo->path, 1600, 1067], [$photo->thumb_path, 480, 320]] as [$path, $width, $height]) {
        $bytes = $disk->get($path);
        $info = getimagesizefromstring($bytes);
        expect($info['mime'])->toBe('image/webp')
            ->and([$info[0], $info[1]])->toBe([$width, $height])
            ->and($bytes)->not->toContain('SECRET')
            ->and($bytes)->not->toContain('EXIF');
    }

    expect($disk->allFiles())->toHaveCount(2); // the original is not kept
});

it('never enlarges a small photo', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/photos", ['photos' => [UploadedFile::fake()->image('small.png', 300, 200)]])
        ->assertCreated();

    $info = getimagesizefromstring(Storage::disk('public')->get(PropertyPhoto::first()->path));
    expect([$info[0], $info[1]])->toBe([300, 200]);
});

it('refuses images too large to process safely', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/photos", ['photos' => [UploadedFile::fake()->image('huge.jpg', 9000, 100)]])
        ->assertUnprocessable();
});

it('deletes both versions when a photo is removed', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]]);
    $photo = PropertyPhoto::first();

    $this->deleteJson("/api/v1/properties/{$this->property->id}/photos/{$photo->id}")->assertOk();

    Storage::disk('public')->assertMissing($photo->path);
    Storage::disk('public')->assertMissing($photo->thumb_path);
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
});

it('saves profile photos as a 256 px WebP square', function () {
    $user = User::factory()->boarder()->create();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/photo', ['photo' => jpegWithExif(1200, 800)])->assertOk();

    $bytes = Storage::disk('local')->get($user->fresh()->photo_path);
    $info = getimagesizefromstring($bytes);
    expect($info['mime'])->toBe('image/webp')
        ->and([$info[0], $info[1]])->toBe([256, 256])
        ->and($bytes)->not->toContain('SECRET');
});

it('converts photos uploaded before variants existed, and is safe to run twice', function () {
    $disk = Storage::disk('public');
    $disk->put('listing-photos/2026/10/old.jpg', file_get_contents(jpegWithExif(2400, 1200)->getRealPath()));
    $photo = $this->property->photos()->create(['path' => 'listing-photos/2026/10/old.jpg', 'is_cover' => true]);

    $user = User::factory()->boarder()->create(['photo_path' => 'profile-photos/2026/10/me.jpg']);
    Storage::disk('local')->put('profile-photos/2026/10/me.jpg', file_get_contents(jpegWithExif(500, 500)->getRealPath()));

    $this->artisan('boardmate:rebuild-images')->assertSuccessful();

    $photo->refresh();
    expect($photo->path)->toEndWith('-large.webp')
        ->and($photo->thumb_path)->toEndWith('-thumb.webp')
        ->and($user->fresh()->photo_path)->toEndWith('-main.webp');
    $disk->assertMissing('listing-photos/2026/10/old.jpg');
    Storage::disk('local')->assertMissing('profile-photos/2026/10/me.jpg');

    $this->artisan('boardmate:rebuild-images')->expectsOutputToContain('Converted 0, failed 0.')->assertSuccessful();
    expect($photo->fresh()->path)->toBe($photo->path);
});

it('keeps tall photos within the size box too', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson("/api/v1/properties/{$this->property->id}/photos", ['photos' => [UploadedFile::fake()->image('tall.jpg', 1500, 4000)]])
        ->assertCreated();

    $photo = PropertyPhoto::first();
    $large = getimagesizefromstring(Storage::disk('public')->get($photo->path));
    $thumb = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_path));
    expect([$large[0], $large[1]])->toBe([600, 1600])
        ->and([$thumb[0], $thumb[1]])->toBe([180, 480]);
});
