<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores uploaded images on the public disk and returns the stored path.
 * Centralizes upload handling so controllers never touch the filesystem
 * directly (document/phase/11 §File Upload). Public URL is derived via
 * Storage::disk('public')->url($path).
 */
class ImageStorageService
{
    /** Store an uploaded file under the given directory; returns the disk path. */
    public function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, 'public');
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
}
