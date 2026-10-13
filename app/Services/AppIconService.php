<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The app's icons — favicon, iPhone home-screen icon and the PWA set — in the
 * admin's theme. Either drawn from the theme colours (the Listee mark: rounded
 * square, white pin, star) or cut from an uploaded image; one mode is active.
 *
 * Icons live on the private disk and are served through the API
 * (/app/icons/{name}.png), so they work whether or not the public storage link
 * exists. Each regeneration gets a new version, which goes into every icon URL
 * so browsers and the PWA install fetch the new files.
 */
class AppIconService
{
    /** name => [size px, full-bleed]. Full-bleed icons fill the square (the OS masks them). */
    public const ICONS = [
        'icon-64' => [64, false],
        'icon-192' => [192, false],
        'icon-512' => [512, false],
        'maskable-512' => [512, true],
        'apple-180' => [180, true],
    ];

    private const DIR = 'app-icons';

    /**
     * Draw this many times larger, then shrink, for smooth edges (GD has no
     * anti-aliased polygons) — capped so the working canvas stays near 1024 px.
     */
    public function __construct(private readonly SettingService $settings) {}

    private function supersample(int $size): int
    {
        return max(2, min(4, intdiv(1024, $size)));
    }

    /** Path of an icon on the private disk, generating the set if it's missing. */
    public function path(string $name): ?string
    {
        if (! isset(self::ICONS[$name])) {
            return null;
        }
        $version = $this->settings->get('appIconVersion');
        $path = $version ? self::DIR."/{$version}/{$name}.png" : null;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            $version = $this->regenerate();
            $path = self::DIR."/{$version}/{$name}.png";
        }

        return $path;
    }

    /** Redraw every icon for the current theme/mode under a new version; returns it. */
    public function regenerate(): string
    {
        $all = $this->settings->all();
        $old = $all['appIconVersion'];
        $version = Str::lower(Str::random(10));

        $upload = $all['appIconMode'] === 'upload' && $all['appIconUploadPath']
            && Storage::disk('local')->exists($all['appIconUploadPath'])
            ? Storage::disk('local')->path($all['appIconUploadPath'])
            : null;

        foreach (self::ICONS as $name => [$size, $fullBleed]) {
            $image = $upload !== null
                ? $this->fromUpload($upload, $size, $fullBleed, (string) $all['themeBackground'])
                : $this->drawMark($size, $fullBleed, (string) $all['themePrimary'], (string) $all['themeAccent']);

            ob_start();
            imagepng($image, null, 9);
            Storage::disk('local')->put(self::DIR."/{$version}/{$name}.png", (string) ob_get_clean());
            imagedestroy($image);
        }

        $this->settings->set(['appIconVersion' => $version]);
        if ($old && $old !== $version) {
            Storage::disk('local')->deleteDirectory(self::DIR."/{$old}");
        }

        return $version;
    }

    /** Keep an uploaded source image (replacing any earlier one). */
    public function storeUpload(string $absolutePath, string $extension): string
    {
        $previous = $this->settings->get('appIconUploadPath');
        $path = self::DIR.'/source-'.Str::lower(Str::random(8)).'.'.$extension;
        Storage::disk('local')->put($path, (string) file_get_contents($absolutePath));
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return $path;
    }

    public function deleteUpload(): void
    {
        $path = $this->settings->get('appIconUploadPath');
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    /** The Listee mark (see the frontend Logo component) in the given colours. */
    private function drawMark(int $size, bool $fullBleed, string $primary, string $accent): GdImage
    {
        $s = $size * $this->supersample($size);
        $img = $this->canvas($s);
        $bg = $this->color($img, $primary);

        if ($fullBleed) {
            imagefilledrectangle($img, 0, 0, $s - 1, $s - 1, $bg);
            // The OS crops to a circle/squircle: keep the mark inside the safe zone.
            $scale = $s / 48 * 0.72;
            $offset = ($s - 48 * $scale) / 2;
        } else {
            $this->roundedRect($img, 0, 0, $s - 1, $s - 1, (int) round($s * 14 / 48), $bg);
            $scale = $s / 48;
            $offset = 0;
        }

        $map = fn (array $pts) => array_merge(...array_map(fn ($p) => [(int) round($offset + $p[0] * $scale), (int) round($offset + $p[1] * $scale)], $pts));

        imagefilledpolygon($img, $map($this->pin()), $this->color($img, '#ffffff'));
        imagefilledpolygon($img, $map($this->star()), $this->color($img, $accent));

        return $this->downsample($img, $size);
    }

    /** An uploaded image as one icon: centre-cropped square; full-bleed ones padded on the theme background. */
    private function fromUpload(string $source, int $size, bool $fullBleed, string $background): GdImage
    {
        $src = imagecreatefromstring((string) file_get_contents($source));
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);

        $out = $this->canvas($size);
        if ($fullBleed) {
            imagefilledrectangle($out, 0, 0, $size - 1, $size - 1, $this->color($out, $background));
        }
        $inner = $fullBleed ? (int) round($size * 0.8) : $size;
        $pad = (int) (($size - $inner) / 2);
        imagecopyresampled($out, $src, $pad, $pad, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $inner, $inner, $side, $side);
        imagedestroy($src);

        return $out;
    }

    /** The pin outline from the Logo SVG path, sampled into points (48×48 space). */
    private function pin(): array
    {
        $pts = [[24, 11]];
        $curves = [
            [[24, 11], [18.5, 11], [14, 15.3], [14, 20.7]],
            [[14, 20.7], [14, 27.6], [22.4, 35.1], [23.3, 35.9]],
            [[24.7, 35.9], [25.6, 35.1], [34, 27.6], [34, 20.7]],
            [[34, 20.7], [34, 15.3], [29.5, 11], [24, 11]],
        ];
        foreach ($curves as [$p0, $p1, $p2, $p3]) {
            for ($i = 1; $i <= 16; $i++) {
                $t = $i / 16;
                $u = 1 - $t;
                $pts[] = [
                    $u ** 3 * $p0[0] + 3 * $u ** 2 * $t * $p1[0] + 3 * $u * $t ** 2 * $p2[0] + $t ** 3 * $p3[0],
                    $u ** 3 * $p0[1] + 3 * $u ** 2 * $t * $p1[1] + 3 * $u * $t ** 2 * $p2[1] + $t ** 3 * $p3[1],
                ];
            }
        }

        return $pts;
    }

    /** The star from the Logo SVG path (48×48 space). */
    private function star(): array
    {
        return [
            [24, 16.4], [25.9, 20.3], [30.2, 20.9], [27.1, 23.9], [27.8, 28.2],
            [24, 26.2], [20.2, 28.2], [20.9, 23.9], [17.8, 20.9], [22.1, 20.3],
        ];
    }

    private function canvas(int $size): GdImage
    {
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);

        return $img;
    }

    private function downsample(GdImage $big, int $size): GdImage
    {
        $small = $this->canvas($size);
        imagealphablending($small, false);
        imagecopyresampled($small, $big, 0, 0, 0, 0, $size, $size, imagesx($big), imagesy($big));
        imagedestroy($big);

        return $small;
    }

    private function roundedRect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    private function color(GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');

        return imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
