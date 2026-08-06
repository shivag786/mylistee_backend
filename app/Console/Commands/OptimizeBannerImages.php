<?php

namespace App\Console\Commands;

use App\Models\Banner;
use App\Services\ImageStorageService;
use Illuminate\Console\Command;

/**
 * Re-optimize existing banner images to WebP (new uploads are already optimized
 * by ImageStorageService). Run once after enabling optimization.
 */
class OptimizeBannerImages extends Command
{
    protected $signature = 'banners:optimize {--force : Re-process even images already stored as .webp}';

    protected $description = 'Convert existing banner images to resized WebP for faster loading';

    public function handle(ImageStorageService $images): int
    {
        $banners = Banner::withTrashed()->whereNotNull('image_path')->get();
        $converted = 0;

        foreach ($banners as $banner) {
            $path = (string) $banner->image_path;
            if (! $this->option('force') && str_ends_with(strtolower($path), '.webp')) {
                continue;
            }

            $newPath = $images->reoptimize($path, 'banners');
            if ($newPath !== $path) {
                $banner->forceFill(['image_path' => $newPath])->saveQuietly();
                $converted++;
                $this->line("  ✓ {$banner->title} → {$newPath}");
            }
        }

        $this->info("Optimized {$converted} banner image(s).");

        return self::SUCCESS;
    }
}
