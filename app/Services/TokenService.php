<?php

namespace App\Services;

use App\Models\CustomerToken;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Rotating customer wallet tokens (Phase 7.3). A short numeric code the customer
 * shows at the counter; valid 30 minutes, regenerated on expiry. Owners resolve
 * it to look the customer up without a phone number.
 */
class TokenService
{
    /** Token lifetime in minutes (PHASE 7.3 — 30 minutes). */
    private const TTL_MINUTES = 30;

    /** Return the customer's active token, minting a fresh one if needed. */
    public function currentFor(User $user): CustomerToken
    {
        $token = $user->customerTokens()
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();

        return $token ?? $this->generate($user);
    }

    /** Mint a new unique token for the customer. */
    public function generate(User $user): CustomerToken
    {
        // Drop any stale tokens for tidiness (active-token lookups filter by expiry anyway).
        $user->customerTokens()->where('expires_at', '<=', Carbon::now())->delete();

        return $user->customerTokens()->create([
            'token' => $this->uniqueCode(),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /** Resolve an active token to its customer, or null. */
    public function resolve(string $token): ?User
    {
        $record = CustomerToken::query()
            ->where('token', trim($token))
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();

        return $record?->user;
    }

    /** A 5-digit code that is not currently active for anyone. */
    private function uniqueCode(): string
    {
        do {
            $code = (string) random_int(10000, 99999);
            $exists = CustomerToken::query()
                ->where('token', $code)
                ->where('expires_at', '>', Carbon::now())
                ->exists();
        } while ($exists);

        return $code;
    }
}
