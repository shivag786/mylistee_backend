<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin REST client for the Razorpay API.
 *
 * Deliberately no SDK: Razorpay's Orders/Payments/Refunds endpoints are plain
 * Basic-auth JSON and the signature scheme is one hash_hmac call, so a package
 * would add a dependency (and its transitive curl/openssl pins) for no gain.
 * Everything here is protocol-level — business rules live in
 * {@see SubscriptionPaymentService}.
 *
 * Money is passed to Razorpay in the *smallest currency unit* (paise for INR).
 * Convert once, at the edge, with {@see self::toPaise()} — never multiply by 100
 * ad hoc, or a ₹499.99 plan silently becomes the wrong charge.
 */
class RazorpayService
{
    /** Razorpay rejects a receipt longer than this. */
    private const RECEIPT_MAX = 40;

    /** Is the gateway usable? Both keys must be present. */
    public function isConfigured(): bool
    {
        return filled(config('razorpay.key_id')) && filled(config('razorpay.key_secret'));
    }

    /** The publishable key the browser needs to open Checkout. Never the secret. */
    public function publicKey(): ?string
    {
        return config('razorpay.key_id');
    }

    /** Rupees → paise, rounded once so float drift can never reach the gateway. */
    public function toPaise(float|string $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }

    public function toRupees(int $paise): float
    {
        return round($paise / 100, 2);
    }

    /**
     * Create a Razorpay order — the object Checkout is opened against.
     *
     * @param  array<string, string>  $notes  Echoed back on the payment + webhook;
     *                                        used to trace a payment to a business/plan.
     * @return array<string, mixed> The raw Razorpay order.
     */
    public function createOrder(int $amountPaise, string $currency, string $receipt, array $notes = []): array
    {
        if ($amountPaise < 100) {
            // Razorpay's floor for INR is ₹1.00. A free plan must never get here.
            throw new RuntimeException('Razorpay orders must be at least 1.00 in the given currency.');
        }

        return $this->request('post', 'orders', [
            'amount' => $amountPaise,
            'currency' => strtoupper($currency),
            'receipt' => substr($receipt, 0, self::RECEIPT_MAX),
            'payment_capture' => config('razorpay.auto_capture') ? 1 : 0,
            'notes' => $notes,
        ]);
    }

    /** @return array<string, mixed> */
    public function fetchOrder(string $orderId): array
    {
        return $this->request('get', "orders/{$orderId}");
    }

    /** @return array<string, mixed> */
    public function fetchPayment(string $paymentId): array
    {
        return $this->request('get', "payments/{$paymentId}");
    }

    /**
     * Refund a captured payment. A null amount refunds the full remaining balance.
     *
     * @param  array<string, string>  $notes
     * @return array<string, mixed> The raw Razorpay refund.
     */
    public function refund(string $paymentId, ?int $amountPaise = null, array $notes = []): array
    {
        $payload = ['speed' => 'normal', 'notes' => $notes];
        if ($amountPaise !== null) {
            $payload['amount'] = $amountPaise;
        }

        return $this->request('post', "payments/{$paymentId}/refund", $payload);
    }

    /**
     * Verify the handshake Checkout hands back to the browser.
     *
     * Razorpay signs `order_id|payment_id` with the key secret. Because the
     * browser is an untrusted courier, a payment is NEVER trusted without this —
     * anyone can POST a made-up payment id at the verify endpoint.
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        return $this->signatureMatches("{$orderId}|{$paymentId}", $signature, (string) config('razorpay.key_secret'));
    }

    /**
     * Verify a webhook against the *raw* request body.
     *
     * Must be the untouched bytes — re-encoding a decoded array reorders keys and
     * breaks the HMAC. Signed with the webhook secret, which is a different
     * secret from the API key.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('razorpay.webhook_secret');

        if ($secret === '') {
            // Fail closed: an unverifiable webhook is an untrusted webhook.
            Log::warning('Razorpay webhook rejected: RAZORPAY_WEBHOOK_SECRET is not set.');

            return false;
        }

        return $this->signatureMatches($rawBody, $signature, $secret);
    }

    private function signatureMatches(string $payload, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        // hash_equals, not ===, so a wrong signature can't be recovered byte by
        // byte from response timing.
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Razorpay is not configured. Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET.');
        }

        $url = rtrim((string) config('razorpay.base_url'), '/')."/{$path}";

        $response = $this->client()->{$method}($url, $payload);

        if ($response->failed()) {
            $error = $response->json('error', []);
            $description = $error['description'] ?? 'Razorpay request failed.';

            // The key secret is never in the payload, but ids and amounts are —
            // log them, they are what makes a failed charge traceable.
            Log::error('Razorpay API error', [
                'path' => $path,
                'status' => $response->status(),
                'code' => $error['code'] ?? null,
                'description' => $description,
            ]);

            throw new RuntimeException($description);
        }

        return (array) $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('razorpay.key_id'),
            (string) config('razorpay.key_secret'),
        )
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('razorpay.timeout', 30))
            ->retry(2, 200, throw: false);
    }
}
