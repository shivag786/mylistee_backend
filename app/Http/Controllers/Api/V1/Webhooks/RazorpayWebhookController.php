<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\RazorpayService;
use App\Services\SubscriptionPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Razorpay server-to-server webhook — the safety net behind the browser
 * handshake. If an owner pays and then closes the tab before the verify call
 * lands, `payment.captured` arrives here and activates the plan anyway.
 *
 * Unauthenticated by necessity (Razorpay has no session), so the HMAC over the
 * raw body IS the authentication. Two rules follow from that:
 *
 *  - Verify against `getContent()`, never a re-encoded array. Re-encoding
 *    reorders keys and the HMAC stops matching.
 *  - Fail closed. No secret configured, or a bad signature, means 401 — never
 *    "process it anyway".
 *
 * Configure at Razorpay Dashboard → Settings → Webhooks with the events:
 * payment.captured, payment.failed, refund.created, refund.processed.
 */
class RazorpayWebhookController extends Controller
{
    public function __construct(
        private readonly RazorpayService $razorpay,
        private readonly SubscriptionPaymentService $payments,
    ) {}

    /** POST /webhooks/razorpay */
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $this->razorpay->verifyWebhookSignature($raw, $signature)) {
            Log::warning('Rejected Razorpay webhook with an invalid signature', [
                'ip' => $request->ip(),
                'event' => $request->input('event'),
            ]);

            return ApiResponse::error('Invalid signature.', status: 401);
        }

        try {
            $result = $this->payments->handleWebhook((array) $request->json()->all());
        } catch (Throwable $e) {
            // A 5xx makes Razorpay retry, which is what we want for a transient
            // failure — but the error must be visible, not swallowed into a retry
            // loop nobody reads.
            Log::error('Razorpay webhook handler failed', [
                'event' => $request->input('event'),
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Webhook processing failed.', status: 500);
        }

        Log::info('Razorpay webhook processed', [
            'event' => $request->input('event'),
            'result' => $result,
        ]);

        return ApiResponse::success(['result' => $result], 'Webhook processed.');
    }
}
