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
    ];

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
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
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
