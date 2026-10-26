<?php

namespace App\Services;

use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Contract\Messaging as FirebaseMessaging;
use App\Support\LaravelCachePool;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Thin wrapper around the Firebase Admin SDK for verifying ID tokens.
 *
 * Lazily initialized and guarded by isConfigured() so the app boots in local
 * dev before a service account is provided. Auth endpoints (Milestone 3) call
 * verifyIdToken(); this scaffold only prepares the connection.
 */
class FirebaseService
{
    private ?FirebaseAuth $auth = null;

    private ?FirebaseMessaging $messaging = null;

    /**
     * Absolute path to the service-account JSON. Resolved against storage/app
     * (as documented) — not the `local` disk, whose root moved to
     * storage/app/private in Laravel 11+.
     */
    private function credentialsPath(): string
    {
        return storage_path('app/'.ltrim((string) config('firebase.credentials'), '/'));
    }

    /**
     * The SDK with a cache that outlives the request: Google's token-signing
     * keys and the service account's access token are fetched once and reused
     * (they carry their own expiry), instead of on every sign-in and push. The
     * file store works whatever CACHE_STORE says.
     */
    private function factory(): Factory
    {
        return (new Factory())
            ->withServiceAccount($this->credentialsPath())
            ->withDefaultCache(new LaravelCachePool(Cache::store('file'), 'firebase:'));
    }

    /** True when a Firebase service account JSON is available on disk. */
    public function isConfigured(): bool
    {
        return is_string(config('firebase.credentials')) && is_file($this->credentialsPath());
    }

    /** Resolve (and cache) the Firebase Auth client, or null if unconfigured. */
    public function auth(): ?FirebaseAuth
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if ($this->auth === null) {
            $this->auth = $this->factory()->createAuth();
        }

        return $this->auth;
    }

    /**
     * Verify a Firebase ID token and return the decoded claims, or null on
     * failure. Backend is the source of truth — never trust the raw token.
     *
     * @return array<string, mixed>|null
     */
    public function verifyIdToken(string $idToken): ?array
    {
        $auth = $this->auth();

        if ($auth === null) {
            return null;
        }

        try {
            $verified = $auth->verifyIdToken($idToken);

            return $verified->claims()->all();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Resolve (and cache) the Cloud Messaging client, or null if unconfigured. */
    public function messaging(): ?FirebaseMessaging
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if ($this->messaging === null) {
            $this->messaging = $this->factory()->createMessaging();
        }

        return $this->messaging;
    }

    /**
     * Best-effort push to a set of device tokens. Returns the list of tokens
     * that are invalid/unknown (so the caller can prune them), or an empty list
     * when messaging is unconfigured. Never throws to the caller.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     * @return list<string>  invalid tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $messaging = $this->messaging();

        if ($messaging === null || $tokens === []) {
            return [];
        }

        try {
            // Data-only on purpose. With a `notification` block the Firebase web
            // SDK shows the push itself AND our service worker shows it again
            // (two notifications), and the SDK's copy ignores data.link on tap.
            // So title/body travel in data and firebase-messaging-sw.js shows it
            // once, opening data.link. Urgency high: deliver to a sleeping phone now.
            $message = CloudMessage::new()
                ->withData(array_merge($data, ['title' => $title, 'body' => $body]))
                ->withWebPushConfig(['headers' => ['Urgency' => 'high', 'TTL' => '86400']]);

            $report = $messaging->sendMulticast($message, $tokens);

            if ($report->hasFailures()) {
                Log::info('FCM push partly failed', [
                    'sent' => $report->successes()->count(),
                    'failed' => $report->failures()->count(),
                    'error' => ($report->failures()->getItems()[0] ?? null)?->error()?->getMessage(),
                ]);
            }

            // Unknown = app removed / token expired; invalid = malformed. Neither
            // will ever work again, so the caller deletes them.
            return array_values(array_unique([...$report->unknownTokens(), ...$report->invalidTokens()]));
        } catch (\Throwable $e) {
            Log::warning('FCM push failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
