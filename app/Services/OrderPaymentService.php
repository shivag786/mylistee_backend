<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Online payment for customer orders.
 *
 * The shop decides how much is taken up front -- the whole total, or a share of
 * it -- and whether cash is still allowed. This service turns that into money:
 * it works out the split, opens a Razorpay order for the online part, and on a
 * verified capture releases the order to the shop.
 *
 * It mirrors SubscriptionPaymentService deliberately, because the rules that
 * make that one safe apply unchanged here:
 *
 *  - The browser is a courier. A payment counts only once the signature checks
 *    out AND Razorpay itself reports it captured for the right order and amount.
 *  - The payment row is locked while it is settled. The verify call and the
 *    webhook can land at the same moment; whichever gets the lock second finds
 *    the work done and stops.
 *
 * An order waiting on its payment stays in AwaitingPayment, which is outside the
 * shop's queue. It becomes a real order -- Placed, visible, rung in -- only on
 * capture.
 */
class OrderPaymentService
{
    public const CHOICE_ONLINE = 'online';

    public const CHOICE_COD = 'cod';

    /** Razorpay will not open an order below one rupee. */
    private const GATEWAY_MINIMUM = 1.0;

    public function __construct(
        private readonly RazorpayService $razorpay,
        private readonly ServiceSettingService $serviceSettings,
        private readonly LoyaltyService $loyalty,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * What checkout may offer at this shop, after the gateway is accounted for.
     *
     * The shop's settings are a preference; the gateway decides what is possible.
     * With no gateway connected nothing can be paid online, so cash is offered
     * whatever the shop chose -- otherwise a shop that switched cash off would
     * quietly stop taking orders the moment the admin had not set Razorpay up.
     *
     * @return array{onlineAvailable: bool, codAvailable: bool, paymentMode: string, partialPercent: int, feePercent: float}
     */
    public function optionsFor(Business $business): array
    {
        $setting = $this->serviceSettings->for($business);
        $online = $this->razorpay->isConfigured();

        return [
            'onlineAvailable' => $online,
            'codAvailable' => ! $online || (bool) $setting->cod_enabled,
            'paymentMode' => $setting->payment_mode ?? 'full',
            'partialPercent' => (int) ($setting->partial_percent ?? 50),
            'feePercent' => $online ? $this->razorpay->feePercent() : 0.0,
        ];
    }

    /**
     * Split an order total for the customer's choice: how much is paid online,
     * and the convenience fee charged on that part.
     *
     * @return array{online: float, fee: float}
     *
     * @throws ValidationException when the choice is not on offer at this shop
     */
    public function split(Business $business, float $total, string $choice): array
    {
        $options = $this->optionsFor($business);

        if ($choice === self::CHOICE_COD) {
            if (! $options['codAvailable']) {
                throw ValidationException::withMessages([
                    'paymentChoice' => ['This shop takes payment online. Please choose Pay online.'],
                ]);
            }

            return ['online' => 0.0, 'fee' => 0.0];
        }

        if (! $options['onlineAvailable']) {
            throw ValidationException::withMessages([
                'paymentChoice' => ['Online payment is not available right now. Please pay at the counter.'],
            ]);
        }

        $online = $this->serviceSettings->for($business)->onlinePortion($total);

        // Nothing worth sending to the gateway -- a cart paid off with coins, or
        // a share too small for Razorpay to accept. There is no money to move,
        // so the order goes straight through rather than failing at the gateway.
        if ($online < self::GATEWAY_MINIMUM) {
            return ['online' => 0.0, 'fee' => 0.0];
        }

        return [
            'online' => $online,
            'fee' => round($online * $options['feePercent'] / 100, 2),
        ];
    }

    /**
     * Open a Razorpay order for an order's online share.
     *
     * Everything Checkout needs rides back in the response, so the frontend never
     * hardcodes a key, an amount or a currency. Charged = online share + fee.
     *
     * @return array<string, mixed>
     */
    public function checkout(Order $order): array
    {
        $order->loadMissing('business', 'customer');

        $charge = round((float) $order->online_amount + (float) $order->convenience_fee, 2);
        $paise = $this->razorpay->toPaise($charge);

        $remote = $this->razorpay->createOrder(
            $paise,
            'INR',
            'ord_'.$order->uuid,
            [
                // Notes ride along on the payment and the webhook, and are how a
                // payment is traced back from the Razorpay dashboard.
                'purpose' => 'customer_order',
                'order_uuid' => (string) $order->uuid,
                'business_id' => (string) $order->business_id,
            ],
        );

        Payment::create([
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'created_by' => $order->customer_id,
            'gateway' => 'razorpay',
            'gateway_order_id' => (string) $remote['id'],
            'receipt' => (string) ($remote['receipt'] ?? ''),
            'status' => PaymentStatus::Created,
            'amount' => $charge,
            'amount_paise' => $paise,
            'currency' => 'INR',
        ]);

        $config = config('razorpay.checkout');

        return [
            'keyId' => $this->razorpay->publicKey(),
            'orderId' => (string) $remote['id'],
            'amount' => $paise,
            'currency' => 'INR',
            'name' => (string) ($order->business?->name ?? ($config['name'] ?? 'Listee')),
            'description' => "Order {$order->token}",
            'logo' => $config['logo'] ?? null,
            'themeColor' => $config['theme_color'] ?? null,
            'prefill' => array_filter([
                'name' => $order->customer?->name,
                'email' => $order->customer?->email,
                'contact' => $order->customer?->phone,
            ]),
        ];
    }

    /**
     * Settle the handshake Checkout hands back to the browser.
     *
     * @throws ValidationException
     */
    public function verify(Order $order, string $orderId, string $paymentId, string $signature): Order
    {
        return DB::transaction(function () use ($order, $orderId, $paymentId, $signature): Order {
            $payment = Payment::where('order_id', $order->id)
                ->where('gateway_order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw ValidationException::withMessages([
                    'razorpayOrderId' => ['We could not find this payment. Please try placing the order again.'],
                ]);
            }

            if ($payment->status === PaymentStatus::Captured) {
                return $order->fresh('items'); // the webhook got here first
            }

            if (! $this->razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
                $this->markFailed($payment, 'SIGNATURE_MISMATCH', 'Payment signature verification failed.');
                Log::warning('Razorpay order-payment signature mismatch', [
                    'orderId' => $order->id,
                    'gatewayOrderId' => $orderId,
                ]);

                throw ValidationException::withMessages([
                    'razorpayPaymentId' => ['We could not verify this payment. If you were charged, it will be refunded automatically.'],
                ]);
            }

            // The signature proves the pair is genuine -- not that money moved, or
            // how much. Ask Razorpay.
            $remote = $this->razorpay->fetchPayment($paymentId);

            $sameOrder = ($remote['order_id'] ?? null) === $orderId;
            $sameAmount = (int) ($remote['amount'] ?? 0) === (int) $payment->amount_paise;

            if (! $sameOrder || ! $sameAmount) {
                Log::error('Razorpay order payment did not match its order', [
                    'paymentId' => $payment->id,
                    'expectedPaise' => $payment->amount_paise,
                    'remotePaise' => $remote['amount'] ?? null,
                ]);

                throw ValidationException::withMessages([
                    'razorpayPaymentId' => ['This payment does not match your order.'],
                ]);
            }

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

            return $order->fresh('items');
        });
    }

    /**
     * The customer walked away from the payment window.
     *
     * The order is withdrawn and any coins spent on it go back. No "order
     * cancelled" notification -- they did not cancel anything, they closed a
     * window, and telling them otherwise would be confusing.
     *
     * A payment already captured is never released: the money has landed, and
     * the order stands.
     */
    public function release(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== OrderStatus::AwaitingPayment) {
                return $order->fresh('items'); // already paid, or already released
            }

            $captured = Payment::where('order_id', $locked->id)
                ->where('status', PaymentStatus::Captured)
                ->exists();

            if ($captured) {
                return $locked->fresh('items');
            }

            $locked->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => Carbon::now()]);

            if ($locked->coins_used > 0 && $locked->customer) {
                $this->loyalty->refund(
                    $locked->customer,
                    $locked->coins_used,
                    $locked->business,
                    $locked,
                    "Coins returned - order {$locked->token} was not paid",
                );
            }

            return $locked->fresh('items');
        });
    }

    /**
     * Give a customer their money back when the shop cancels an order they paid
     * for online.
     *
     * Without this a cancel would keep the customer's money for an order that
     * no longer exists. The whole captured amount goes back, convenience fee
     * included: the customer did not choose to cancel, so they should not be
     * out of pocket for it.
     *
     * Throws when the refund fails, so the cancel is refused rather than going
     * through with the money still held -- the owner can simply try again.
     *
     * @throws ValidationException
     */
    public function refundFor(Order $order): void
    {
        $payment = Payment::where('order_id', $order->id)
            ->where('status', PaymentStatus::Captured)
            ->lockForUpdate()
            ->first();

        if ($payment === null || $payment->gateway_payment_id === null) {
            return; // nothing was taken online
        }

        try {
            $refund = $this->razorpay->refund(
                $payment->gateway_payment_id,
                null, // the full amount
                ['reason' => 'order_cancelled', 'order_uuid' => (string) $order->uuid],
            );
        } catch (\Throwable $e) {
            Log::error('Refund for a cancelled order failed', [
                'orderId' => $order->id,
                'paymentId' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'status' => ['This order was paid online and the refund could not be issued. Please try again.'],
            ]);
        }

        $payment->forceFill([
            'status' => PaymentStatus::Refunded,
            'refunded_amount' => $payment->amount,
            'refunded_at' => now(),
            'meta' => array_merge($payment->meta ?? [], ['refunds' => [$refund]]),
        ])->save();
    }

    /**
     * Refund an order payment from the admin panel, all of it or part.
     *
     * A full refund withdraws the order too, if it is still in play -- otherwise
     * the customer has their money back while the shop still sees a paid order
     * to fulfil. Once the order is settled (marked paid or completed) a refund
     * is goodwill: the money goes back and the order stands. A partial refund
     * never touches the order.
     *
     * @throws ValidationException
     */
    public function adminRefund(Payment $payment, ?float $amount, User $actor, ?string $reason = null): Payment
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

            if ($isFull) {
                $this->withdrawRefundedOrder($payment->order_id);
            }

            return $payment->refresh();
        });
    }

    /**
     * Withdraw an order whose payment was refunded in full -- but only while it
     * is still in play.
     *
     * Settled orders (paid or completed) stand: by then the shop has handed the
     * order over, and coins may already have been earned on it. Pulling those
     * back is a different decision from giving money back, so a refund there is
     * goodwill, not a reversal.
     */
    private function withdrawRefundedOrder(?int $orderId): void
    {
        if ($orderId === null) {
            return;
        }

        $order = Order::whereKey($orderId)->lockForUpdate()->first();
        $inPlay = [OrderStatus::AwaitingPayment, OrderStatus::Placed, OrderStatus::Confirmed];

        if ($order === null || ! in_array($order->status, $inPlay, true)) {
            return;
        }

        $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => Carbon::now()]);
        $order->loadMissing('customer', 'business');

        if ($order->coins_used > 0 && $order->customer && $order->business) {
            $this->loyalty->refund(
                $order->customer,
                $order->coins_used,
                $order->business,
                $order,
                "Coins returned - order {$order->token} was refunded",
            );
        }

        // Unlike a closed payment window, this was done to them -- say so.
        if ($order->customer) {
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Order refunded',
                "Your order {$order->token} at {$order->business?->name} was cancelled and your payment refunded.",
                ['link' => '/orders'],
            );
        }
    }

    /**
     * A Razorpay webhook, if it concerns an order payment.
     *
     * Returns 'not_ours' for anything that is not, so the caller can hand the
     * same payload to the subscription handler. Unknown ids are 'not_ours' too:
     * a plan payment does not belong here, and treating it as an unknown order
     * would swallow it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): string
    {
        $event = (string) ($payload['event'] ?? '');

        // A refund issued straight from the Razorpay dashboard never passes
        // through adminRefund(); this is how the order still gets withdrawn.
        if (in_array($event, ['refund.created', 'refund.processed'], true)) {
            $refund = $payload['payload']['refund']['entity'] ?? null;

            return is_array($refund) ? $this->webhookRefunded($refund) : 'not_ours';
        }

        $entity = $payload['payload']['payment']['entity'] ?? null;

        if (! is_array($entity)) {
            return 'not_ours';
        }

        $gatewayOrderId = (string) ($entity['order_id'] ?? '');
        if ($gatewayOrderId === '') {
            return 'not_ours';
        }

        $isOurs = Payment::where('gateway_order_id', $gatewayOrderId)->whereNotNull('order_id')->exists();
        if (! $isOurs) {
            return 'not_ours';
        }

        return match ($event) {
            'payment.captured', 'order.paid' => $this->webhookCaptured($gatewayOrderId, $entity),
            'payment.failed' => $this->webhookFailed($gatewayOrderId, $entity),
            default => 'ignored',
        };
    }

    /** @param  array<string, mixed>  $entity */
    private function webhookCaptured(string $gatewayOrderId, array $entity): string
    {
        return DB::transaction(function () use ($gatewayOrderId, $entity): string {
            $payment = Payment::where('gateway_order_id', $gatewayOrderId)->lockForUpdate()->first();

            if ($payment === null) {
                return 'unknown';
            }

            if ($payment->status === PaymentStatus::Captured) {
                return 'duplicate'; // verify() already handled it
            }

            if ((int) ($entity['amount'] ?? 0) !== (int) $payment->amount_paise) {
                Log::error('Razorpay order-payment webhook amount mismatch', [
                    'gatewayOrderId' => $gatewayOrderId,
                    'expectedPaise' => $payment->amount_paise,
                    'remotePaise' => $entity['amount'] ?? null,
                ]);

                return 'mismatch';
            }

            $this->capture($payment, $entity);

            return 'captured';
        });
    }

    /**
     * Mirror a refund made outside the app -- the Razorpay dashboard -- so the
     * books and the order both match. Not an order payment ⇒ 'not_ours', and
     * the plan handler takes it.
     *
     * @param  array<string, mixed>  $entity
     */
    private function webhookRefunded(array $entity): string
    {
        return DB::transaction(function () use ($entity): string {
            $payment = Payment::where('gateway_payment_id', (string) ($entity['payment_id'] ?? ''))
                ->whereNotNull('order_id')
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return 'not_ours';
            }

            // Razorpay reports each refund, not a running total; a refund made
            // through adminRefund() is already on the books.
            $refunded = $this->razorpay->toRupees((int) ($entity['amount'] ?? 0));
            if ((float) $payment->refunded_amount >= $refunded) {
                return 'duplicate';
            }

            $isFull = $refunded >= (float) $payment->amount;

            $payment->forceFill([
                'refunded_amount' => $refunded,
                'refunded_at' => now(),
                'status' => $isFull ? PaymentStatus::Refunded : $payment->status,
                'meta' => array_merge($payment->meta ?? [], ['refunds' => [$entity]]),
            ])->save();

            if ($isFull) {
                $this->withdrawRefundedOrder($payment->order_id);
            }

            return 'refunded';
        });
    }

    /** @param  array<string, mixed>  $entity */
    private function webhookFailed(string $gatewayOrderId, array $entity): string
    {
        $payment = Payment::where('gateway_order_id', $gatewayOrderId)->first();

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

    /**
     * Money landed: record it and release the order to the shop.
     *
     * Always called inside a transaction holding the payment row's lock.
     *
     * @param  array<string, mixed>  $remote
     */
    private function capture(Payment $payment, array $remote, ?string $signature = null): void
    {
        $payment->forceFill([
            'gateway_payment_id' => $remote['id'] ?? $payment->gateway_payment_id,
            'gateway_signature' => $signature ?? $payment->gateway_signature,
            'status' => PaymentStatus::Captured,
            'method' => $remote['method'] ?? null,
            'paid_at' => now(),
            'meta' => array_merge($payment->meta ?? [], ['payment' => $remote]),
        ])->save();

        $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();

        if ($order === null) {
            // Money taken, order gone. Never let this pass quietly.
            Log::critical('Captured Razorpay payment has no order to release', [
                'paymentId' => $payment->id,
                'gatewayOrderId' => $payment->gateway_order_id,
            ]);

            return;
        }

        if ($order->status === OrderStatus::Cancelled) {
            // The customer closed the window, the order was released and its
            // coins handed back -- and then the payment went through anyway. UPI
            // does this: the app approves after the modal has already closed.
            // The money is real, so the order must be too.
            Log::warning('Captured payment for a released order; reinstating it', [
                'orderId' => $order->id,
                'paymentId' => $payment->id,
            ]);

            $this->reclaimCoins($order);
        }

        $order->update([
            'status' => OrderStatus::Placed,
            'online_paid_at' => Carbon::now(),
            // "Placed" is when it became a real order for the shop, which for an
            // online order is the moment the money landed -- not when checkout
            // was opened.
            'placed_at' => Carbon::now(),
            'cancelled_at' => null,
        ]);

        $order->loadMissing('business.owner');

        if ($order->business?->owner) {
            $due = $order->amountDue();
            $paidLine = $due > 0
                ? "₹{$order->online_amount} paid online · ₹{$due} to collect."
                : "₹{$order->online_amount} paid online in full.";

            $this->notifications->notify(
                $order->business->owner,
                NotificationType::OrderPlaced,
                "New order {$order->token} · {$order->serviceLabel()}",
                $paidLine,
                ['link' => '/business/orders'],
            );
        }
    }

    /**
     * Take back the coins that release() refunded, now the order stands again.
     *
     * Otherwise the customer keeps the discount and gets the coins back too.
     *
     * This must never throw: it runs inside capture(), and an exception there
     * rolls back the whole capture -- the customer charged, the payment left
     * unrecorded. So it takes what the balance allows and logs any shortfall
     * (they may have spent the coins elsewhere in between) instead of failing.
     */
    private function reclaimCoins(Order $order): void
    {
        if ($order->coins_used <= 0 || $order->customer === null || $order->business === null) {
            return;
        }

        try {
            $available = $this->loyalty->balanceForBusiness($order->customer, $order->business);
            $reclaim = min($available, (int) $order->coins_used);

            if ($reclaim > 0) {
                $this->loyalty->spend(
                    $order->customer,
                    $reclaim,
                    $order->business,
                    $order,
                    "Coins reapplied - order {$order->token} was paid after all",
                );
            }

            if ($reclaim < $order->coins_used) {
                Log::warning('Could not reclaim all coins for a reinstated order', [
                    'orderId' => $order->id,
                    'coinsUsed' => $order->coins_used,
                    'reclaimed' => $reclaim,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Reclaiming coins for a reinstated order failed', [
                'orderId' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
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
}
