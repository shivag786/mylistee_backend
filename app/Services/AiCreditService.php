<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\AiCreditPack;
use App\Models\Business;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * AI add-on packs: extra AI answers a shop buys with Razorpay when the month's
 * plan answers run out. Bought answers go into `businesses.ai_credits`, are
 * used only after the plan's answers, and never expire.
 *
 * Same handshake as plan payments: checkout → Razorpay Checkout → verify, with
 * the webhook as the safety net when the tab closes before verify. Payments
 * carry purpose `ai_credits` so no other handler touches them.
 */
class AiCreditService
{
    public const PURPOSE = 'ai_credits';

    public function __construct(
        private readonly RazorpayService $razorpay,
        private readonly AuditService $audit,
    ) {}

    /** @return list<array<string, mixed>> packs on sale, cheapest first */
    public function packsForSale(): array
    {
        return AiCreditPack::query()->where('is_active', true)->orderBy('position')->orderBy('price')->get()
            ->map(fn (AiCreditPack $p) => $this->presentPack($p))->all();
    }

    /** @return array<string, mixed> */
    public function presentPack(AiCreditPack $p): array
    {
        return [
            'id' => $p->uuid,
            'name' => $p->name,
            'answers' => $p->answers,
            'price' => (float) $p->price,
            'perAnswer' => $p->answers > 0 ? round((float) $p->price / $p->answers, 2) : null,
            'isActive' => $p->is_active,
            'position' => $p->position,
        ];
    }

    public function paymentsLive(): bool
    {
        return $this->razorpay->isConfigured();
    }

    /**
     * Open a Razorpay order for a pack.
     *
     * @return array<string, mixed> what Razorpay Checkout needs
     */
    public function checkout(Business $business, AiCreditPack $pack, User $actor): array
    {
        if (! $pack->is_active) {
            throw ValidationException::withMessages(['packId' => ['This AI pack is no longer on sale.']]);
        }
        if (! $this->razorpay->isConfigured()) {
            throw new RuntimeException('Online payments are not available right now. Please try again later.');
        }

        $amountPaise = $this->razorpay->toPaise((float) $pack->price);
        $receipt = Str::limit("rcpt_ai_{$business->id}_".now()->format('YmdHis'), 40, '');
        $order = $this->razorpay->createOrder($amountPaise, 'INR', $receipt, [
            'businessId' => (string) $business->uuid,
            'businessName' => Str::limit($business->name, 60, ''),
            'purpose' => self::PURPOSE,
            'pack' => $pack->name,
        ]);

        $payment = Payment::create([
            'business_id' => $business->id,
            'purpose' => self::PURPOSE,
            'created_by' => $actor->id,
            'gateway' => 'razorpay',
            'gateway_order_id' => $order['id'],
            'receipt' => $receipt,
            'status' => PaymentStatus::Created,
            'amount' => $this->razorpay->toRupees($amountPaise),
            'amount_paise' => $amountPaise,
            'currency' => 'INR',
            'meta' => ['order' => $order, 'packId' => $pack->uuid, 'pack' => $pack->name, 'answers' => $pack->answers],
        ]);

        return [
            'paymentId' => $payment->uuid,
            'keyId' => $this->razorpay->publicKey(),
            'orderId' => $order['id'],
            'amount' => $amountPaise,
            'amountDisplay' => (float) $payment->amount,
            'currency' => 'INR',
            'name' => config('razorpay.checkout.name'),
            'description' => "{$pack->name} · {$pack->answers} AI answers",
            'themeColor' => config('razorpay.checkout.theme_color'),
            'logo' => config('razorpay.checkout.logo'),
            'prefill' => [
                'name' => $business->owner_name ?: $actor->name,
                'email' => $business->email ?: $actor->email,
                'contact' => $business->phone ?: ($actor->phone ?? ''),
            ],
            'notes' => ['businessName' => $business->name, 'pack' => $pack->name],
        ];
    }

    /**
     * The browser's handshake. Idempotent: a second call for a captured
     * payment returns the same result without adding the answers twice.
     *
     * @return array{credited: int, credits: int}
     */
    public function verify(Business $business, string $orderId, string $paymentId, string $signature): array
    {
        if (! $this->razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            throw ValidationException::withMessages(['signature' => ['We could not confirm this payment. If money was taken it will be added within a few minutes.']]);
        }

        return DB::transaction(function () use ($business, $orderId, $paymentId, $signature): array {
            $payment = Payment::query()->where('business_id', $business->id)->where('purpose', self::PURPOSE)
                ->where('gateway_order_id', $orderId)->lockForUpdate()->first();
            if ($payment === null) {
                throw ValidationException::withMessages(['orderId' => ['Payment not found.']]);
            }
            if ($payment->status === PaymentStatus::Captured) {
                return ['credited' => 0, 'credits' => $this->balance($business)];
            }

            $remote = $this->razorpay->fetchPayment($paymentId);
            if (($remote['order_id'] ?? null) !== $orderId || (int) ($remote['amount'] ?? 0) !== (int) $payment->amount_paise) {
                Log::error('AI pack payment does not match its order', ['orderId' => $orderId, 'paymentId' => $paymentId]);
                throw ValidationException::withMessages(['orderId' => ['This payment does not match the order.']]);
            }

            $credited = $this->capture($payment, $remote + ['signature' => $signature]);

            return ['credited' => $credited, 'credits' => $this->balance($business)];
        });
    }

    /**
     * Webhook hand-off; 'not_ours' for anything that is not an AI pack payment.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): string
    {
        $event = (string) ($payload['event'] ?? '');
        $entity = $payload['payload']['payment']['entity'] ?? null;
        $orderId = is_array($entity) ? (string) ($entity['order_id'] ?? '') : '';
        if ($orderId === '' || ! in_array($event, ['payment.captured', 'order.paid', 'payment.failed'], true)) {
            return 'not_ours';
        }

        return DB::transaction(function () use ($event, $entity, $orderId): string {
            $payment = Payment::query()->where('gateway_order_id', $orderId)->where('purpose', self::PURPOSE)->lockForUpdate()->first();
            if ($payment === null) {
                return 'not_ours';
            }
            if ($payment->status === PaymentStatus::Captured) {
                return 'duplicate';
            }
            if ($event === 'payment.failed') {
                $payment->forceFill([
                    'status' => PaymentStatus::Failed,
                    'error_code' => (string) ($entity['error_code'] ?? 'PAYMENT_FAILED'),
                    'error_description' => (string) ($entity['error_description'] ?? 'The payment could not be completed.'),
                    'failed_at' => now(),
                ])->save();

                return 'failed';
            }
            if ((int) ($entity['amount'] ?? 0) !== (int) $payment->amount_paise) {
                Log::error('AI pack webhook amount mismatch', ['orderId' => $orderId]);

                return 'mismatch';
            }
            $this->capture($payment, $entity);

            return 'captured';
        });
    }

    /** The admin gives (or takes back, with a negative number) AI answers. */
    public function grant(Business $business, int $answers, User $admin, ?string $note = null): int
    {
        DB::transaction(function () use ($business, $answers): void {
            $row = Business::query()->whereKey($business->id)->lockForUpdate()->first();
            $row->forceFill(['ai_credits' => max(0, (int) $row->ai_credits + $answers)])->save();
        });
        $this->audit->log($admin, 'ai_credits.granted', $business,
            ($answers >= 0 ? "Gave {$answers}" : 'Took back '.abs($answers)).' AI answers'.($note ? ": {$note}" : ''),
            ['answers' => $answers]);

        return $this->balance($business);
    }

    public function balance(Business $business): int
    {
        return (int) Business::query()->whereKey($business->id)->value('ai_credits');
    }

    /** @param  array<string, mixed>  $remote */
    private function capture(Payment $payment, array $remote): int
    {
        $answers = (int) ($payment->meta['answers'] ?? 0);
        $payment->forceFill([
            'gateway_payment_id' => $remote['id'] ?? $payment->gateway_payment_id,
            'gateway_signature' => $remote['signature'] ?? $payment->gateway_signature,
            'status' => PaymentStatus::Captured,
            'method' => $remote['method'] ?? null,
            'paid_at' => now(),
            'meta' => array_merge($payment->meta ?? [], ['payment' => array_diff_key($remote, ['signature' => true])]),
        ])->save();

        Business::query()->whereKey($payment->business_id)->increment('ai_credits', $answers);
        Log::info('AI pack paid', ['business' => $payment->business_id, 'answers' => $answers, 'paymentId' => $payment->id]);

        return $answers;
    }
}
