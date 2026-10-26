<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The look of the Listee Business (vendor) phone app: its colours and how long
 * the splash screen stays up. Set by the admin, served in GET /config as
 * `vendorApp`, and applied by the app on its next launch.
 *
 * Kept apart from the web theme on purpose: the business app has its own brand
 * look (cream, charcoal, sunshine yellow, teal) whatever the customer site wears.
 */
class VendorAppThemeService
{
    public const KEY = 'vendorAppTheme';

    public const COLORS = ['primary', 'onPrimary', 'secondary', 'accent', 'background', 'surface', 'ink'];

    /** The Listee Business brand — taken from the app's splash artwork. */
    public const DEFAULTS = [
        'primary' => '#FAC83A',     // sunshine yellow: main buttons, highlights
        'onPrimary' => '#16232A',   // text on primary
        'secondary' => '#198077',   // teal: links, active states, success
        'accent' => '#EF5B4C',      // coral: new-order alerts, badges
        'background' => '#FEFBF3',  // cream
        'surface' => '#FFFFFF',     // cards
        'ink' => '#16232A',         // charcoal: headings, body text, dark bars
        'splashSeconds' => 3,
    ];

    /** @return array<string, mixed> stored values over the defaults */
    public function get(): array
    {
        $row = Setting::query()->where('key', self::KEY)->value('value');
        $stored = is_array($row) && is_array($row['value'] ?? null) ? $row['value'] : [];

        $out = self::DEFAULTS;
        foreach (self::COLORS as $key) {
            if (isset($stored[$key]) && is_string($stored[$key]) && preg_match('/^#[0-9A-Fa-f]{6}$/', $stored[$key])) {
                $out[$key] = strtoupper($stored[$key]);
            }
        }
        if (isset($stored['splashSeconds'])) {
            $out['splashSeconds'] = max(1, min(6, (int) $stored['splashSeconds']));
        }

        return $out;
    }

    /**
     * Save the given values; keys left out keep their stored value.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(array $values): array
    {
        $next = array_merge($this->get(), array_intersect_key($values, self::DEFAULTS));

        Setting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => ['value' => $next], 'group' => 'general'],
        );

        return $this->get();
    }

    /** Back to the Listee Business brand. */
    public function reset(): array
    {
        Setting::query()->where('key', self::KEY)->delete();

        return $this->get();
    }
}
