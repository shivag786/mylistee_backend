<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploaded images on the public disk and returns the stored path.
 * Centralizes upload handling so controllers never touch the filesystem
 * directly (document/phase/11 §File Upload).
 *
 * On upload, raster images are resized (capped width) and re-encoded to WebP —
 * typically a 5–10× size reduction, so pages load fast on mobile. Anything the
 * encoder can't handle (SVG, GIF, corrupt files, or a host without GD/WebP) is
 * stored unchanged, so an upload never fails because of optimization.
 */
class ImageStorageService
{
    /** Images wider than this are scaled down (px). Preserves aspect ratio. */
    private const MAX_WIDTH = 1600;

    /** WebP quality (0–100). 82 is visually lossless for photos at a small size. */
    private const QUALITY = 82;

    /** Source mime types we re-encode. Others are stored as-is. */
    private const OPTIMIZABLE = ['image/jpeg', 'image/png', 'image/webp'];

    /** Store an uploaded file under the given directory; returns the disk path. */
    public function store(UploadedFile $file, string $directory): string
    {
        $webp = $this->toWebp($file->getRealPath(), (string) $file->getMimeType());

        if ($webp === null) {
            return $file->store($directory, 'public'); // fallback: store unchanged
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.webp';
        Storage::disk('public')->put($path, $webp);

        return $path;
    }

    /**
     * Re-optimize an image already on the public disk (used by the backfill
     * command). Returns the new .webp path, or the original path if it couldn't
     * be optimized. The old file is removed when a new one is written.
     */
    public function reoptimize(string $path, string $directory): string
    {
        $absolute = Storage::disk('public')->path($path);
        if (! is_file($absolute)) {
            return $path;
        }

        $webp = $this->toWebp($absolute, (string) (mime_content_type($absolute) ?: ''));
        if ($webp === null) {
            return $path;
        }

        $newPath = trim($directory, '/').'/'.Str::random(40).'.webp';
        Storage::disk('public')->put($newPath, $webp);
        $this->delete($path);

        return $newPath;
    }

    /** Delete a previously stored path (best-effort, ignores missing files). */
    public function delete(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /** Absolute public URL for a stored path, or null. */
    public function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Resize (if needed) and encode an image file to WebP. Returns the WebP
     * binary, or null when the file can't/shouldn't be optimized.
     */
    private function toWebp(?string $absolutePath, string $mime): ?string
    {
        if ($absolutePath === null
            || ! is_file($absolutePath)
            || ! in_array($mime, self::OPTIMIZABLE, true)
            || ! function_exists('imagewebp')) {
            return null;
        }

        $data = @file_get_contents($absolutePath);
        if ($data === false) {
            return null;
        }

        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return null;
        }

        try {
            $image = $this->applyExifOrientation($image, $absolutePath, $mime);
            $image = $this->resizeIfWide($image);

            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagewebp($image, null, self::QUALITY);
            $binary = ob_get_clean();

            return $ok && $binary !== false && $binary !== '' ? $binary : null;
        } finally {
            imagedestroy($image);
        }
    }

    /** Scale down to MAX_WIDTH if wider, preserving aspect ratio and alpha. */
    private function resizeIfWide(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= self::MAX_WIDTH) {
            return $image;
        }

        $newWidth = self::MAX_WIDTH;
        $newHeight = (int) round($height * ($newWidth / $width));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $dst;
    }

    /**
     * Apply JPEG EXIF orientation so re-encoded phone photos aren't rotated.
     * No-op when EXIF isn't available or the image is already upright.
     */
    private function applyExifOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? null;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated !== null && $rotated !== false) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
