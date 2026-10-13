<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppIconService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installable-app pieces that follow the admin's theme: the icons and the
 * web app manifest. Public; the frontend points <link rel="icon">,
 * <link rel="apple-touch-icon"> and <link rel="manifest"> here.
 */
class AppBrandingController extends Controller
{
    public function __construct(
        private readonly AppIconService $icons,
        private readonly SettingService $settings,
    ) {}

    /** GET /app/icons/{name}.png?v= — cached for a year; a new version is a new URL. */
    public function icon(string $name): Response
    {
        $path = $this->icons->path($name);
        abort_if($path === null, 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** GET /app/manifest.webmanifest */
    public function manifest(): JsonResponse
    {
        $all = $this->settings->all();
        // Make sure there is a version to put in the icon URLs.
        $version = $all['appIconVersion'] ?: $this->icons->regenerate();
        $front = rtrim((string) config('app.frontend_url'), '/');
        $icon = fn (string $name) => route('app.icon', ['name' => $name]).'?v='.$version;
        $brand = (string) $all['brandName'];

        return response()->json([
            'name' => "{$brand} — Rewards Around You",
            'short_name' => $brand,
            'description' => 'Scan, spin and win rewards at local businesses near you.',
            'theme_color' => $all['themePrimary'],
            'background_color' => $all['themeBackground'],
            'display' => 'standalone',
            'orientation' => 'portrait',
            // Absolute: this manifest is served from the API's origin, the app from the frontend's.
            'id' => $front.'/',
            'start_url' => $front.'/',
            'scope' => $front.'/',
            'categories' => ['shopping', 'lifestyle', 'food'],
            'icons' => [
                ['src' => $icon('icon-64'), 'sizes' => '64x64', 'type' => 'image/png'],
                ['src' => $icon('icon-192'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $icon('icon-512'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $icon('maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Wallet', 'short_name' => 'Wallet', 'url' => $front.'/wallet'],
                ['name' => 'Nearby', 'short_name' => 'Nearby', 'url' => $front.'/nearby'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            // Short: a theme change should reach new installs soon.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
