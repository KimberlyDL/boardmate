<?php

namespace App\Services\Files;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Re-encodes an uploaded photo into WebP variants (Files module). Re-encoding
 * applies the EXIF rotation and then drops all metadata, including GPS.
 */
class ImageVariants
{
    public const QUALITY = 80;

    /** Largest side accepted on upload; keeps GD's memory use bounded. */
    public const MAX_SIDE = 8200;

    /**
     * @param  array<string, array{fit: 'inside'|'square', size: int}>  $specs
     * @return array<string, string> variant => WebP bytes
     */
    public function make(string $binary, array $specs): array
    {
        $this->ensureMemory();
        $manager = new ImageManager(Driver::class, autoOrientation: true, decodeAnimation: false, strip: true);

        // Resizing the full photo is the slow part, so make the biggest 'inside' size first and
        // shrink that same image for the next one (e.g. 1600 px, then the 480 px thumb from it).
        $ordered = $specs;
        uasort($ordered, fn (array $a, array $b) => $b['size'] <=> $a['size']);

        $variants = [];
        $image = null;
        foreach ($ordered as $name => $spec) {
            // 'inside': the longer side is at most `size` (portrait photos too); never enlarged.
            // A square crop always starts from the original, and nothing reuses a cropped image.
            if ($spec['fit'] === 'square') {
                $variant = $manager->decodeBinary($binary)->cover($spec['size'], $spec['size']);
            } else {
                $image = ($image ?? $manager->decodeBinary($binary))->scaleDown(width: $spec['size'], height: $spec['size']);
                $variant = $image;
            }

            $variants[$name] = $variant->encode(new WebpEncoder(quality: self::QUALITY, strip: true))->toString();
        }

        // Same order as the specs given
        return array_replace(array_fill_keys(array_keys($specs), ''), $variants);
    }

    /** A full-size phone photo needs more than PHP's default 128 MB to decode. */
    private function ensureMemory(): void
    {
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && $this->bytes((string) $limit) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
    }

    private function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
