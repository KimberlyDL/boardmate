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

        $variants = [];
        foreach ($specs as $name => $spec) {
            $image = $manager->decodeBinary($binary);
            // 'inside': the longer side is at most `size` (portrait photos too); never enlarged.
            $image = $spec['fit'] === 'square'
                ? $image->cover($spec['size'], $spec['size'])
                : $image->scaleDown(width: $spec['size'], height: $spec['size']);

            $variants[$name] = $image->encode(new WebpEncoder(quality: self::QUALITY, strip: true))->toString();
        }

        return $variants;
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
