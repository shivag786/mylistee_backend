<?php

namespace App\Services\WhatsApp;

use App\Enums\UserRole;
use App\Models\ShopCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Forgot PIN: a 6-digit code on WhatsApp, then a new PIN. Uses the template
 * the admin set for the `otp` message (an Authentication template).
 *
 * The answer to "send a code" is the same whether or not the number has an
 * account, so the form can't be used to find out who is on Listee. Only a hash
 * of the code is kept, for 10 minutes, with 5 tries.
 */
class WhatsAppOtpService
{
    private const TTL_MINUTES = 10;

    private const MAX_TRIES = 5;

    /** Codes one number may ask for per hour. */
    private const MAX_SENDS = 3;

    public function __construct(
        private readonly WhatsAppEventService $events,
        private readonly WhatsAppSender $sender,
    ) {}

    public function available(): bool
    {
        return $this->events->templateFor('otp') !== null;
    }

    public function send(string $phoneInput): void
    {
        $template = $this->events->templateFor('otp');
        if ($template === null) {
            throw ValidationException::withMessages(['mobile' => ['Resetting the PIN by WhatsApp is not available right now. Ask support to reset it.']]);
        }

        $phone = $this->normalize($phoneInput);
        $key = "pin-otp-send:{$phone}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_SENDS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['mobile' => ["Too many codes asked for. Try again in {$minutes} min."]]);
        }
        RateLimiter::hit($key, 3600);

        $user = $this->findUser($phone);
        if ($user === null) {
            return; // same answer as a real send
        }

        $code = (string) random_int(100000, 999999);
        Cache::put($this->cacheKey($phone), ['hash' => Hash::make($code), 'tries' => 0], now()->addMinutes(self::TTL_MINUTES));

        $this->sender->send($template, $phone, 'otp', ['otp_code' => $code, 'customer_name' => $user->name]);
    }

    /** Check the code and set the new PIN. */
    public function reset(string $phoneInput, string $code, string $pin): User
    {
        $phone = $this->normalize($phoneInput);
        $key = $this->cacheKey($phone);
        $entry = Cache::get($key);
        $wrong = ValidationException::withMessages(['code' => ['That code is wrong or has expired. Ask for a new one.']]);

        if (! is_array($entry) || $entry['tries'] >= self::MAX_TRIES) {
            throw $wrong;
        }
        if (! Hash::check($code, $entry['hash'])) {
            $entry['tries']++;
            Cache::put($key, $entry, now()->addMinutes(self::TTL_MINUTES));
            throw $wrong;
        }

        $user = $this->findUser($phone);
        if ($user === null) {
            throw $wrong;
        }

        Cache::forget($key);
        $user->forceFill([
            'pin' => $pin,
            'pin_plain' => $user->role === UserRole::BusinessOwner ? $pin : null,
        ])->save();
        // Lift any sign-in lockout the forgotten PIN caused, and sign out other devices.
        RateLimiter::clear('pin-login:'.mb_strtolower((string) $user->phone));
        $user->tokens()->delete();

        return $user;
    }

    private function normalize(string $input): string
    {
        $phone = ShopCustomer::normalizePhone($input);
        if ($phone === null) {
            throw ValidationException::withMessages(['mobile' => ['Enter a valid 10-digit mobile number.']]);
        }

        return $phone;
    }

    private function findUser(string $phone): ?User
    {
        return User::query()
            ->whereIn('phone', [$phone, "+91{$phone}", "91{$phone}", "0{$phone}"])
            ->whereNotNull('pin')
            ->first();
    }

    private function cacheKey(string $phone): string
    {
        return "pin-otp:{$phone}";
    }
}
