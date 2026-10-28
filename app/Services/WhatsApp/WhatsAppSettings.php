<?php

namespace App\Services\WhatsApp;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The Interakt connection and which template each automatic message uses
 * (admin → WhatsApp). One settings row; the API key and webhook secret are
 * stored encrypted and never leave the server — the admin screen only learns
 * whether one is saved.
 *
 * Kept out of SettingService on purpose, like VendorAppThemeService.
 */
class WhatsAppSettings
{
    public const KEY = 'whatsapp';

    /**
     * Messages Listee sends by itself, and to whom. Each one goes out only when
     * the admin picks an approved template for it.
     */
    public const EVENTS = [
        'otp' => ['label' => 'Login code (forgot PIN)', 'to' => 'customer', 'hint' => 'Authentication template with a one-time code'],
        'order_placed_owner' => ['label' => 'New order — to the shop', 'to' => 'shop', 'hint' => 'Rings the shop on WhatsApp too'],
        'order_placed' => ['label' => 'Order placed — to the customer', 'to' => 'customer', 'hint' => 'Order confirmation'],
        'order_accepted' => ['label' => 'Order accepted', 'to' => 'customer', 'hint' => 'The shop took the order'],
        'order_ready' => ['label' => 'Order ready', 'to' => 'customer', 'hint' => 'Ready to collect / on its way'],
        'order_completed' => ['label' => 'Order completed', 'to' => 'customer', 'hint' => 'Thank-you after the order'],
        'order_cancelled' => ['label' => 'Order cancelled', 'to' => 'customer', 'hint' => 'The order was cancelled'],
    ];

    private const DEFAULTS = [
        'enabled' => false,
        'apiKey' => '',
        'webhookSecret' => '',
        'countryCode' => '+91',
        'events' => [],
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @return array<string, mixed> with secrets decrypted — server use only */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $row = Setting::query()->where('key', self::KEY)->value('value');
        $stored = is_array($row) && is_array($row['value'] ?? null) ? $row['value'] : [];
        $out = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $out['apiKey'] = $this->decrypt($out['apiKey']);
        $out['webhookSecret'] = $this->decrypt($out['webhookSecret']);
        $out['events'] = is_array($out['events']) ? $out['events'] : [];

        return $this->cache = $out;
    }

    /**
     * Safe for the admin screen: secrets replaced by whether one is saved.
     *
     * @return array<string, mixed>
     */
    public function masked(): array
    {
        $all = $this->all();

        return [
            'enabled' => (bool) $all['enabled'],
            'apiKeySet' => filled($all['apiKey']),
            'webhookSecretSet' => filled($all['webhookSecret']),
            'countryCode' => $all['countryCode'],
            'events' => (object) $all['events'],
        ];
    }

    /** Connected and switched on: messages may go out. */
    public function active(): bool
    {
        $all = $this->all();

        return (bool) $all['enabled'] && filled($all['apiKey']);
    }

    public function apiKey(): string
    {
        return (string) $this->all()['apiKey'];
    }

    public function webhookSecret(): string
    {
        return (string) $this->all()['webhookSecret'];
    }

    public function countryCode(): string
    {
        return (string) $this->all()['countryCode'];
    }

    /** The template uuid picked for an automatic message, or null. */
    public function eventTemplate(string $event): ?string
    {
        $uuid = $this->all()['events'][$event] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * Save the given values. A blank secret keeps the stored one, so saving
     * the switch never wipes the key.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(array $values): array
    {
        $next = $this->all();
        foreach (['enabled', 'countryCode'] as $key) {
            if (array_key_exists($key, $values)) {
                $next[$key] = $values[$key];
            }
        }
        if (array_key_exists('events', $values)) {
            $next['events'] = array_filter(
                array_intersect_key((array) $values['events'], self::EVENTS),
                fn ($v) => is_string($v) && $v !== '',
            );
        }
        foreach (['apiKey', 'webhookSecret'] as $key) {
            if (filled($values[$key] ?? null)) {
                $next[$key] = trim((string) $values[$key]);
            }
        }
        if (! empty($values['clearApiKey'])) {
            $next['apiKey'] = '';
            $next['enabled'] = false;
        }

        $store = $next;
        $store['apiKey'] = $next['apiKey'] === '' ? '' : Crypt::encryptString($next['apiKey']);
        $store['webhookSecret'] = $next['webhookSecret'] === '' ? '' : Crypt::encryptString($next['webhookSecret']);

        Setting::updateOrCreate(['key' => self::KEY], ['value' => ['value' => $store], 'group' => 'integrations']);
        $this->cache = null;

        return $this->masked();
    }

    private function decrypt(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return '';
        }
    }
}
