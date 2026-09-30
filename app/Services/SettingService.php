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
