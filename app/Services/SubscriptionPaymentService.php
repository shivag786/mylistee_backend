<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Buying a business plan with Razorpay (one-time order per billing period).
 *
 * The flow, and why each step exists:
 *
 *   1. checkout()  — we create a Razorpay order and a local `created` Payment row.
 *                    The row exists before the owner pays, so abandoned attempts
 *                    are still visible to support.
 *   2. Checkout    — the browser pays. We trust nothing it says.
 *   3. verify()    — the signature proves the order/payment pair really came from
 *                    Razorpay, and a server-side fetch confirms the amount and
 *                    that it is actually captured. Only then does the plan go live.
 *   4. webhook()   — the safety net. If the owner closes the tab between paying
 *                    and step 3, `payment.captured` activates the plan anyway.
 *
 * Steps 3 and 4 race by design, so every mutation takes a row lock on the payment
 * and is a no-op once the payment is already captured.
 */
class SubscriptionPaymentService
{
    public function __construct(
        private readonly RazorpayService $razorpay,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Open a checkout for a paid plan. Returns everything the browser needs to
     * launch Razorpay Checkout — including the publishable key, so the frontend
     * carries no gateway configuration of its own.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function checkout(Business $business, Plan $plan, User $actor): array
    {
        if ($plan->isFree() || $plan->is_default) {
            // Nothing to charge — the caller should have used the plain switch.
            throw ValidationException::withMessages([
                'planKey' => ['The Free plan does not require a payment.'],
            ]);
        }

        if (! $this->razorpay->isConfigured()) {
            throw new RuntimeException('Online payments are not available right now. Please try again later.');
        }

        $amountPaise = $this->razorpay->toPaise($plan->price);

        // Receipt is capped at 40 chars by Razorpay and shows up in their
        // dashboard — keep it greppable: plan + business + when.
        $receipt = Str::limit("rcpt_{$plan->key}_{$business->id}_".now()->format('YmdHis'), 40, '');

        $order = $this->razorpay->createOrder($amountPaise, $plan->currency, $receipt, [
            'businessId' => (string) $business->uuid,
            'businessName' => Str::limit($business->name, 60, ''),
            'planKey' => $plan->key,
            'planName' => $plan->name,
        ]);

        $payment = Payment::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'created_by' => $actor->id,
            'gateway' => 'razorpay',
            'gateway_order_id' => $order['id'],
            'receipt' => $receipt,
            'status' => PaymentStatus::Created,
            'amount' => $this->razorpay->toRupees($amountPaise),
            'amount_paise' => $amountPaise,
            'currency' => $plan->currency,
            'meta' => ['order' => $order],
        ]);

        return [
            'paymentId' => $payment->uuid,
            'keyId' => $this->razorpay->publicKey(),
            'orderId' => $order['id'],
            // Paise — Razorpay Checkout expects the smallest unit, same as the order.
            'amount' => $amountPaise,
            'amountDisplay' => (float) $payment->amount,
            'currency' => $plan->currency,
            'planKey' => $plan->key,
            'planName' => $plan->name,
            'name' => config('razorpay.checkout.name'),
            'description' => "{$plan->name} plan · {$this->intervalLabel($plan->interval)}",
            'themeColor' => config('razorpay.checkout.theme_color'),
            'logo' => config('razorpay.checkout.logo'),
            'prefill' => [
                'name' => $business->owner_name ?: $actor->name,
                'email' => $business->email ?: $actor->email,
                'contact' => $business->phone ?: ($actor->phone ?? ''),
            ],
            'notes' => [
                'businessName' => $business->name,
                'planName' => $plan->name,
            ],
        ];
    }

    /**
     * Verify the Checkout handshake and, if it holds up, activate the plan.
     *
     * Idempotent: calling it again for an already-captured payment returns the
     * same success instead of double-activating.
     *
     * @return array{payment: Payment, activated: bool}
     *
     * @throws ValidationException
     */
    public function verify(Business $business, string $orderId, string $paymentId, string $signature): array
    {
        $payment = $this->lockedPayment($business, $orderId);

        if ($payment->status === PaymentStatus::Captured) {
            return ['payment' => $payment, 'activated' => false]; // already done
        }

        if (! $this->razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            $this->markFailed($payment, 'SIGNATURE_MISMATCH', 'Payment signature verification failed.');

            Log::warning('Razorpay signature mismatch', [
                'businessId' => $business->id,
                'orderId' => $orderId,
                'paymentId' => $paymentId,
            ]);

            throw ValidationException::withMessages([
                'razorpayPaymentId' => ['We could not verify this payment. If you were charged, the amount will be refunded automatically.'],
            ]);
        }

        // The signature only proves the pair is authentic — not that money moved,
        // nor how much. Ask Razorpay directly.
        $remote = $this->razorpay->fetchPayment($paymentId);

        $this->assertMatchesOrder($payment, $remote, $orderId);

        if (($remote['status'] ?? null) !== 'captured') {
            $this->markFailed(
                $payment,
                (string) ($remote['error_code'] ?? 'NOT_CAPTURED'),
                (string) ($remote['error_description'] ?? 'The payment was not completed.'),
            );

            throw ValidationException::withMessages([
                'razorpayPaymentId' => ['This payment has not been completed. Please try again.'],
            ]);
        }

        $this->capture($payment, $remote, $signature);

        return ['payment' => $payment->refresh(), 'activated' => true];
    }

    /**
     * Record an attempt the browser reported as failed (the `payment.failed`
     * handler in Checkout). Advisory only — it never activates or blocks
     * anything, it just keeps abandoned attempts honest in the payments log.
     *
     * @param  array<string, mixed>  $error
     */
    public function recordClientFailure(Business $business, string $orderId, array $error): ?Payment
    {
        $payment = Payment::where('business_id', $business->id)
            ->where('gateway_order_id', $orderId)
            ->first();

        if ($payment === null || $payment->status === PaymentStatus::Captured) {
            return $payment;
        }

        $this->markFailed(
            $payment,
            (string) ($error['code'] ?? 'PAYMENT_FAILED'),
            (string) ($error['description'] ?? 'The payment could not be completed.'),
        );

        return $payment->refresh();
    }

    /**
     * Handle a verified Razorpay webhook.
     *
     * The signature is checked by the caller against the raw body, so by the time
     * we are here the payload is trusted. Unknown events are acknowledged and
     * ignored — Razorpay retries anything we do not 2xx, and we do not want
     * retries for events we simply do not use.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): string
    {
        $event = (string) ($payload['event'] ?? '');
        $entity = $payload['payload']['payment']['entity']
            ?? $payload['payload']['refund']['entity']
            ?? null;

        if (! is_array($entity)) {
            return 'ignored';
        }

        return match ($event) {
            'payment.captured', 'order.paid' => $this->webhookCaptured($entity),
            'payment.failed' => $this->webhookFailed($entity),
            'refund.created', 'refund.processed' => $this->webhookRefunded($entity),
            default => 'ignored',
        };
    }

    /**
     * Refund a captured payment (admin action, per the published refund policy).
     *
     * A full refund also ends the subscription immediately — the owner cannot keep
     * a plan they were refunded for. A partial refund leaves the plan running: it
     * is a goodwill adjustment, not a reversal.
     *
     * @throws ValidationException
     */
    public function refund(Payment $payment, ?float $amount, User $actor, ?string $reason = null): Payment
    {
        if ($payment->status !== PaymentStatus::Captured) {
            throw ValidationException::withMessages([
                'payment' => ['Only a captured payment can be refunded.'],
            ]);
        }

        $refundable = $payment->refundableAmount();
        $amount ??= $refundable;

        if ($amount <= 0 || $amount > $refundable) {
            throw ValidationException::withMessages([
                'amount' => ["Enter an amount between 0.01 and {$refundable}."],
            ]);
        }

        $isFull = abs($amount - $refundable) < 0.01;

        $refund = $this->razorpay->refund(
            (string) $payment->gateway_payment_id,
            $this->razorpay->toPaise($amount),
            [
                'reason' => Str::limit($reason ?? 'Refunded by Listee support', 200, ''),
                'refundedBy' => (string) $actor->uuid,
            ],
        );

        return DB::transaction(function () use ($payment, $amount, $isFull, $refund, $reason): Payment {
            $payment->forceFill([
                'refunded_amount' => (float) $payment->refunded_amount + $amount,
                'refunded_at' => now(),
                'status' => $isFull ? PaymentStatus::Refunded : $payment->status,
                'meta' => array_merge($payment->meta ?? [], [
                    'refunds' => array_merge($payment->meta['refunds'] ?? [], [$refund]),
                    'refundReason' => $reason,
                ]),
            ])->save();

            $payment->invoice?->update([
                'status' => $isFull ? InvoiceStatus::Refunded : InvoiceStatus::Paid,
            ]);

            if ($isFull && $payment->subscription !== null) {
                // End it now, not at period end — the money went back.
                $payment->subscription->update([
                    'status' => SubscriptionStatus::Cancelled,
                    'auto_renew' => false,
                    'cancelled_at' => now(),
                    'ends_at' => now(),
                ]);
                $payment->business?->forgetPlanCache();
            }

            return $payment->refresh();
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * Fetch the payment row for this business under a row lock, so verify() and
     * the webhook cannot both activate the same order.
     *
     * @throws ValidationException
     */
    private function lockedPayment(Business $business, string $orderId): Payment
    {
        $payment = Payment::where('business_id', $business->id)
            ->where('gateway_order_id', $orderId)
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            throw ValidationException::withMessages([
                'razorpayOrderId' => ['We could not find this payment. Please start the upgrade again.'],
            ]);
        }

        return $payment;
    }

    /**
     * Guard against a payment being replayed against a different (cheaper) order.
     *
     * @param  array<string, mixed>  $remote
     *
     * @throws ValidationException
     */
    private function assertMatchesOrder(Payment $payment, array $remote, string $orderId): void
    {
        $sameOrder = ($remote['order_id'] ?? null) === $orderId;
        $sameAmount = (int) ($remote['amount'] ?? 0) === (int) $payment->amount_paise;

        if ($sameOrder && $sameAmount) {
            return;
        }

        Log::error('Razorpay payment did not match its order', [
            'paymentId' => $payment->id,
            'expectedOrder' => $orderId,
            'remoteOrder' => $remote['order_id'] ?? null,
            'expectedPaise' => $payment->amount_paise,
            'remotePaise' => $remote['amount'] ?? null,
        ]);

        throw ValidationException::withMessages([
            'razorpayPaymentId' => ['This payment does not match the plan you selected.'],
        ]);
    }

    /**
     * Mark the money as landed and switch the business onto the plan it bought.
     *
     * @param  array<string, mixed>  $remote
     */
    private function capture(Payment $payment, array $remote, ?string $signature = null): void
    {
        DB::transaction(function () use ($payment, $remote, $signature): void {
            $payment->forceFill([
                'gateway_payment_id' => $remote['id'] ?? $payment->gateway_payment_id,
                'gateway_signature' => $signature ?? $payment->gateway_signature,
                'status' => PaymentStatus::Captured,
                'method' => $remote['method'] ?? null,
                'paid_at' => now(),
                'meta' => array_merge($payment->meta ?? [], ['payment' => $remote]),
            ])->save();

            $business = $payment->business;
            $plan = $payment->plan;

            if ($business === null || $plan === null) {
                // Money taken, plan gone — never let this pass silently.
                Log::critical('Captured Razorpay payment has no business/plan to activate', [
                    'paymentId' => $payment->id,
                    'orderId' => $payment->gateway_order_id,
                ]);

                return;
            }

            $this->subscriptions->subscribe($business, $plan, $payment->createdBy, $payment);
        });
    }

    private function markFailed(Payment $payment, string $code, string $description): void
    {
        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'error_code' => Str::limit($code, 64, ''),
            'error_description' => Str::limit($description, 255, ''),
            'failed_at' => now(),
        ])->save();
    }

    /** @param  array<string, mixed>  $entity */
    private function webhookCaptured(array $entity): string
    {
        $orderId = (string) ($entity['order_id'] ?? '');
        if ($orderId === '') {
            return 'ignored';
        }

        return DB::transaction(function () use ($orderId, $entity): string {
            $payment = Payment::where('gateway_order_id', $orderId)->lockForUpdate()->first();

            if ($payment === null) {
                Log::warning('Razorpay webhook for an unknown order', ['orderId' => $orderId]);

                return 'unknown';
            }

            if ($payment->status === PaymentStatus::Captured) {
                return 'duplicate'; // verify() already handled it
            }

            if ((int) ($entity['amount'] ?? 0) !== (int) $payment->amount_paise) {
                Log::error('Razorpay webhook amount mismatch', [
                    'orderId' => $orderId,
                    'expectedPaise' => $payment->amount_paise,
                    'remotePaise' => $entity['amount'] ?? null,
                ]);

                return 'mismatch';
            }

            $this->capture($payment, $entity);

            return 'captured';
        });
    }

    /** @param  array<string, mixed>  $entity */
    private function webhookFailed(array $entity): string
    {
        $payment = Payment::where('gateway_order_id', (string) ($entity['order_id'] ?? ''))->first();

        if ($payment === null || $payment->status === PaymentStatus::Captured) {
            return 'ignored';
        }

        $this->markFailed(
            $payment,
            (string) ($entity['error_code'] ?? 'PAYMENT_FAILED'),
            (string) ($entity['error_description'] ?? 'The payment could not be completed.'),
        );

        return 'failed';
    }

    /** @param  array<string, mixed>  $entity */
    private function webhookRefunded(array $entity): string
    {
        $payment = Payment::where('gateway_payment_id', (string) ($entity['payment_id'] ?? ''))->first();

        if ($payment === null) {
            return 'unknown';
        }

        // A refund issued straight from the Razorpay dashboard never passed
        // through refund() — mirror it here so our books match theirs.
        $refunded = $this->razorpay->toRupees((int) ($entity['amount'] ?? 0));
        if ((float) $payment->refunded_amount >= $refunded) {
            return 'duplicate';
        }

        $payment->forceFill([
            'refunded_amount' => $refunded,
            'refunded_at' => now(),
            'status' => $refunded >= (float) $payment->amount ? PaymentStatus::Refunded : $payment->status,
            'meta' => array_merge($payment->meta ?? [], ['refunds' => [$entity]]),
        ])->save();

        if ($refunded >= (float) $payment->amount) {
            $payment->invoice?->update(['status' => InvoiceStatus::Refunded]);
        }

        return 'refunded';
    }

    private function intervalLabel(string $interval): string
    {
        return match ($interval) {
            'quarter' => 'billed every 3 months',
            'year' => 'billed yearly',
            'lifetime' => 'one-time payment',
            default => 'billed monthly',
        };
    }
}
