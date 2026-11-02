<?php

namespace App\Services;

/**
 * Shrinks oversized phone photos before storage. Skips when GD is missing or the
 * image is already small enough.
 */
class DocumentImageOptimizer
{
    /** Longest edge for stored document photos (enough for OCR, not 12MP originals). */
    private const MAX_EDGE = 2048;

    private const JPEG_QUALITY = 82;

    private const WEBP_QUALITY = 80;

    /**
     * @return array{contents: string, mime: string}|null Optimized bytes, or null to keep original.
     */
    public function optimize(string $binary, ?string $mime): ?array
    {
        if ($binary === '' || ! extension_loaded('gd')) {
            return null;
        }

        $mime = strtolower((string) $mime);
        if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1) {
            return null;
        }

        $edge = max($width, $height);
        $needsResize = $edge > self::MAX_EDGE;
        // Always re-encode JPEGs/WebPs over ~400KB to drop EXIF + quality; skip tiny files.
        $needsReencode = strlen($binary) > 400_000;

        if (! $needsResize && ! $needsReencode) {
            return null;
        }

        if ($needsResize) {
            $scale = self::MAX_EDGE / $edge;
            $newW = max(1, (int) round($width * $scale));
            $newH = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($newW, $newH);
            if ($canvas === false) {
                return null;
            }

            if ($mime === 'image/png') {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);
            $source = $canvas;
        }

        $encoded = $this->encode($source, $mime);

        if ($encoded === null || $encoded === '' || strlen($encoded) >= strlen($binary)) {
            return null;
        }

        return [
            'contents' => $encoded,
            'mime' => $mime === 'image/jpg' ? 'image/jpeg' : $mime,
        ];
    }

    private function encode(\GdImage $image, string $mime): ?string
    {
        ob_start();

        $ok = match ($mime) {
            'image/png' => imagepng($image, null, 6),
            'image/webp' => function_exists('imagewebp')
                ? imagewebp($image, null, self::WEBP_QUALITY)
                : false,
            default => imagejpeg($image, null, self::JPEG_QUALITY),
        };

        $contents = ob_get_clean();

        return $ok && is_string($contents) ? $contents : null;
    }
}
