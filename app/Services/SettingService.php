<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Arr;

/**
 * Platform settings (document/phase/14 §Platform Settings). A known set of keys
 * with typed defaults; the scalar value is stored wrapped as {"value": …} so one
 * JSON column holds any type. Unknown keys are ignored on write.
 */
class SettingService
{
    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'brandName' => 'Listee',
        'supportEmail' => 'support@listee.app',
        'supportPhone' => '',
        'currency' => 'INR',
        'timezone' => 'Asia/Kolkata',
        'defaultLanguage' => 'en',
        'maintenanceMode' => false,
        'maintenanceMessage' => "We'll be back shortly.",
        // Custom new-order alert sound (uploaded by an admin). URL is public; the
        // disk path is kept so the old file can be replaced/removed. Null = the
        // built-in synthesized "ding".
        'orderSoundUrl' => null,
        'orderSoundPath' => null,

        // Razorpay, set from the admin panel instead of the server's .env so the
        // gateway can be connected (or its keys rotated) without a deploy. Empty
        // means "fall back to .env" -- see RazorpayService.
        'razorpayKeyId' => '',
        'razorpayKeySecret' => '',
        'razorpayWebhookSecret' => '',
        // Convenience fee, as a percentage of the amount a customer pays online.
        // Added to their bill, never taken out of the shop's price.
        'razorpayFeePercent' => 2.0,

        // Which sign-in methods the customer login page offers. At least one
        // stays on -- see set().
        'loginGoogle' => true,
        'loginMobile' => false,

        // App theme, applied on every device through GET /config. Defaults are
        // the stock palette in the frontend's tokens.css; hover and soft shades
        // are derived from these on the client.
        'themePrimary' => '#e23744',
        'themeSecondary' => '#2d3436',
        'themeAccent' => '#fbbf24',
        'themeSuccess' => '#22c55e',
        'themeInfo' => '#3b82f6',
        'themeWarning' => '#f59e0b',
        'themeDanger' => '#ef4444',
        'themeBackground' => '#f8f9fa',
        'themeSurface' => '#ffffff',
        'themeText' => '#212121',
        'themeFont' => 'Poppins',
        'themeHeadingFont' => 'Poppins',

        // Optional colours: '' = follow the main palette (see THEME_EXTRA_COLORS).
        'themeHeader' => '',
        'themeNav' => '',
        'themeNavActive' => '',
        'themeGradientFrom' => '',
        'themeGradientTo' => '',
        'themeStar' => '',
        'themeOffer' => '',

        // light | dark | system (follow the phone), with dark mode's own colours.
        'themeMode' => 'light',
        'themeDarkBackground' => '#1e1e1e',
        'themeDarkSurface' => '#262626',
        'themeDarkText' => '#f3f4f6',

        'themeRadius' => 'rounded',   // sharp | rounded | pill
        'themeTextSize' => 'normal',  // small | normal | large

        // App icon (favicon, home-screen and PWA icons): drawn from the theme
        // colours ('auto') or made from an uploaded image ('upload'). Only one
        // is active. The version changes on every regeneration, so browsers
        // fetch the new icons instead of serving cached ones.
        'appIconMode' => 'auto',
        'appIconUploadPath' => null,
        'appIconVersion' => null,

        // Tint shop-page menu cards with each dish's own colours (glow under the
        // photo, faint wash across the card). The full-screen dish viewer keeps
        // its colours either way.
        'menuCardColors' => true,

        // Customer home page sections in the admin's order — a list of
        // {id, enabled, title, slot}. Null = the built-in order (see frontend
        // homeSections.ts). Sections missing from a saved list still show, at
        // their default place, so a new section never silently disappears.
        'homeLayout' => null,
    ];

    /** Home page section ids the layout may list. Mirrors the frontend registry. */
    public const HOME_SECTIONS = [
        'hero', 'stories', 'banners_top', 'search', 'rewards', 'categories', 'deals', 'reels',
        'combos', 'banners_after_combos', 'festival', 'recommended', 'new', 'nearby',
    ];

    /** When a section shows: always, or only in one part of the day. */
    public const HOME_SLOTS = ['all', 'morning', 'afternoon', 'evening', 'night'];

    /** Colour settings, all "#rrggbb". */
    public const THEME_COLORS = [
        'themePrimary', 'themeSecondary', 'themeAccent', 'themeSuccess', 'themeInfo',
        'themeWarning', 'themeDanger', 'themeBackground', 'themeSurface', 'themeText',
    ];

    /** Optional colours: "#rrggbb", or '' to follow the main palette. */
    public const THEME_EXTRA_COLORS = [
        'themeHeader', 'themeNav', 'themeNavActive', 'themeGradientFrom', 'themeGradientTo', 'themeStar', 'themeOffer',
    ];

    /** Dark-mode colours, all "#rrggbb". */
    public const THEME_DARK_COLORS = ['themeDarkBackground', 'themeDarkSurface', 'themeDarkText'];

    /** Fonts an admin can choose — each must be on Google Fonts (the client loads it from there). */
    public const THEME_FONTS = [
        'Poppins', 'Inter', 'Nunito', 'Montserrat', 'Roboto', 'Lato', 'Open Sans',
        'Rubik', 'DM Sans', 'Outfit', 'Playfair Display', 'Mukta', 'Hind', 'Baloo 2',
    ];

    /**
     * Settings that must never leave the server. Reads hand back an empty string
     * and a `<key>Set` flag instead, and a blank write keeps the stored value --
     * so the admin form can show "a secret is saved" without the API ever
     * returning it, and saving an unrelated field cannot wipe it.
     *
     * @var list<string>
     */
    private const SECRETS = ['razorpayKeySecret', 'razorpayWebhookSecret'];

    /**
     * All settings merged over their defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Setting::query()->pluck('value', 'key');

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $row = $stored->get($key);
            $out[$key] = is_array($row) ? Arr::get($row, 'value', $default) : $default;
        }

        return $out;
    }

    /**
     * All settings, safe to send to a browser: secrets are blanked and replaced
     * by whether one is stored. Use this for anything that leaves the server.
     *
     * @return array<string, mixed>
     */
    public function masked(): array
    {
        $all = $this->all();

        foreach (self::SECRETS as $key) {
            $all[$key.'Set'] = filled($all[$key]);
            $all[$key] = '';
        }

        return $all;
    }

    /**
     * The public theme block for GET /config.
     *
     * @return array<string, mixed>
     */
    public function theme(): array
    {
        $all = $this->all();

        $colors = [];
        foreach (self::THEME_COLORS as $key) {
            // "themePrimary" → "primary"
            $colors[lcfirst(substr($key, 5))] = (string) $all[$key];
        }

        $extras = [];
        foreach (self::THEME_EXTRA_COLORS as $key) {
            // "themeNavActive" → "navActive"; '' → null (follow the palette).
            $extras[lcfirst(substr($key, 5))] = filled($all[$key]) ? (string) $all[$key] : null;
        }

        return [
            'colors' => $colors,
            'extras' => $extras,
            'mode' => (string) $all['themeMode'],
            'dark' => [
                'background' => (string) $all['themeDarkBackground'],
                'surface' => (string) $all['themeDarkSurface'],
                'text' => (string) $all['themeDarkText'],
            ],
            'radius' => (string) $all['themeRadius'],
            'textSize' => (string) $all['themeTextSize'],
            'font' => (string) $all['themeFont'],
            'headingFont' => (string) $all['themeHeadingFont'],
            'menuCardColors' => (bool) $all['menuCardColors'],
            'icon' => [
                'mode' => (string) $all['appIconMode'],
                'version' => $all['appIconVersion'],
            ],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::query()->where('key', $key)->value('value');

        return is_array($row) ? Arr::get($row, 'value', $default) : ($default ?? (self::DEFAULTS[$key] ?? null));
    }

    /**
     * Persist a set of settings (only known keys).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>  the full settings map after the update
     */
    public function set(array $values): array
    {
        // Never let an admin switch off every way in. With both off, no customer
        // could sign in at all -- and nothing on the login page would say why.
        // Checked against what is stored, not just this request: turning off
        // Google while mobile is already off must be caught too.
        if (array_key_exists('loginGoogle', $values) || array_key_exists('loginMobile', $values)) {
            $current = $this->all();
            $google = (bool) ($values['loginGoogle'] ?? $current['loginGoogle']);
            $mobile = (bool) ($values['loginMobile'] ?? $current['loginMobile']);
            if (! $google && ! $mobile) {
                $values['loginGoogle'] = true;
            }
        }

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            // A blank secret means "leave it as it is" -- the form never has the
            // stored value to send back, so blank cannot mean "clear it".
            if (in_array($key, self::SECRETS, true) && blank($value)) {
                continue;
            }
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value], 'group' => 'general'],
            );
        }

        return $this->all();
    }
}
