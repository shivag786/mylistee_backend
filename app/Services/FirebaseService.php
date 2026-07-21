<?php

namespace App\Services;

use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Contract\Messaging as FirebaseMessaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

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
            $this->auth = (new Factory())->withServiceAccount($this->credentialsPath())->createAuth();
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
            $this->messaging = (new Factory())->withServiceAccount($this->credentialsPath())->createMessaging();
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
            $message = CloudMessage::new()
                ->withNotification(FcmNotification::create($title, $body))
                ->withData($data);

            $report = $messaging->sendMulticast($message, $tokens);

            return $report instanceof MulticastSendReport
                ? $report->unknownTokens()
                : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
