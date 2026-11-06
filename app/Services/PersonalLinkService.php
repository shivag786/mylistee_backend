<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Payment;
use App\Models\PersonalLinkGrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Personal link — store mode for any shop, free and never expiring.
 *
 * A shop whose personal link is on keeps customers who arrive by its QR or
 * link inside that shop (see Business::hasStoreMode()). The owner just
 * switches it on or off — there is no trial, no pack and no expiry.
 *
 * It used to be sold by the day. Nothing can be bought any more, but the
 * webhook handling below stays so a payment an admin's link collects before
 * it expires (links lapse 15 days after they were sent) is still recorded.
 */
class PersonalLinkService
{
    public const PURPOSE = 'personal_link';

    /**
     * Everything the owner screen shows.
     *
     * @return array<string, mixed>
     */
    public function state(Business $business): array
    {
        return [
            'enabled' => (bool) $business->personal_link_enabled,
            'active' => $business->hasActivePersonalLink(),
            'neverExpires' => true,
            // Kept (empty) for older app builds that still read them.
            'expiresAt' => null,
            'daysLeft' => 0,
            'trialAvailable' => false,
            'trialDays' => 0,
            'onTrial' => false,
            'packs' => [],
            'paymentsLive' => false,
            'history' => [],
        ];
    }

    /** Owner's switch — on or off, nothing else. */
    public function setEnabled(Business $business, bool $enabled): Business
    {
        $business->forceFill(['personal_link_enabled' => $enabled])->save();
        $business->forgetPlanCache();

        return $business;
    }

    /**
     * Webhook hand-off. Returns 'not_ours' for anything that is not a
     * personal-link payment, so the caller passes it on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): string
    {
        $event = (string) ($payload['event'] ?? '');

        // An admin's payment link was paid (the related payment.captured event
        // has an order we never created, so this is the one that counts).
        if ($event === 'payment_link.paid') {
            $linkId = (string) ($payload['payload']['payment_link']['entity']['id'] ?? '');

            return $linkId === '' ? 'not_ours' : $this->applyLinkPaid($linkId, (array) ($payload['payload']['payment']['entity'] ?? []));
        }

        $entity = $payload['payload']['payment']['entity'] ?? null;
        $orderId = is_array($entity) ? (string) ($entity['order_id'] ?? '') : '';

        if ($orderId === '' || ! in_array($event, ['payment.captured', 'order.paid', 'payment.failed'], true)) {
            return 'not_ours';
        }

        return DB::transaction(function () use ($event, $entity, $orderId): string {
            $payment = Payment::where('gateway_order_id', $orderId)
                ->where('purpose', self::PURPOSE)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return 'not_ours';
            }
            if ($payment->status === PaymentStatus::Captured) {
                return 'duplicate';
            }

            if ($event === 'payment.failed') {
                $this->markFailed(
                    $payment,
                    (string) ($entity['error_code'] ?? 'PAYMENT_FAILED'),
                    (string) ($entity['error_description'] ?? 'The payment could not be completed.'),
                );

                return 'failed';
            }

            if ((int) ($entity['amount'] ?? 0) !== (int) $payment->amount_paise) {
                Log::error('Personal link webhook amount mismatch', ['orderId' => $orderId]);

                return 'mismatch';
            }

            $this->capture($payment, $entity);

            return 'captured';
        });
    }

    /**
     * A payment link was paid: capture its payment row and record the grant.
     * Safe to call twice (webhook retries).
     *
     * @param  array<string, mixed>  $paymentEntity
     */
    private function applyLinkPaid(string $linkId, array $paymentEntity): string
    {
        return DB::transaction(function () use ($linkId, $paymentEntity): string {
            $payment = Payment::where('gateway_order_id', $linkId)
                ->where('purpose', self::PURPOSE)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return 'not_ours';
            }
            if ($payment->status === PaymentStatus::Captured) {
                return 'duplicate';
            }
            if (isset($paymentEntity['amount']) && (int) $paymentEntity['amount'] < (int) $payment->amount_paise) {
                Log::error('Personal link payment link underpaid', ['linkId' => $linkId]);

                return 'mismatch';
            }

            $this->capture($payment, array_filter($paymentEntity, fn ($v) => $v !== null));

            return 'captured';
        });
    }

    // ---------------------------------------------------------------- internals

    /** @param  array<string, mixed>  $remote */
    private function capture(Payment $payment, array $remote): void
    {
        $payment->forceFill([
            'gateway_payment_id' => $remote['id'] ?? $payment->gateway_payment_id,
            'status' => PaymentStatus::Captured,
            'method' => $remote['method'] ?? null,
            'paid_at' => now(),
            'meta' => array_merge($payment->meta ?? [], ['payment' => $remote]),
        ])->save();

        $business = Business::whereKey($payment->business_id)->lockForUpdate()->first();
        if ($business === null) {
            Log::critical('Captured personal link payment has no business', ['paymentId' => $payment->id]);

            return;
        }

        // A payment link raised by an admin already has its (pending) grant.
        $grant = PersonalLinkGrant::where('payment_id', $payment->id)->lockForUpdate()->first()
            ?? new PersonalLinkGrant([
                'source' => 'online',
                'pack' => $payment->meta['pack'] ?? null,
                'days' => (int) ($payment->meta['days'] ?? 0),
                'amount' => $payment->amount,
                'payment_id' => $payment->id,
            ]);

        if ($grant->exists && $grant->status === 'active') {
            return; // already applied
        }

        // The link never expires now, so paying just switches it on and keeps
        // the record (days and expiry are history only).
        $before = $business->personal_link_expires_at;
        $after = ($before !== null && now()->lt($before) ? Carbon::instance($before) : now())->copy()->addDays($grant->days);

        $business->forceFill(['personal_link_enabled' => true, 'personal_link_expires_at' => $after])->save();
        $business->forgetPlanCache();

        $grant->forceFill([
            'business_id' => $business->id,
            'status' => 'active',
            'expires_before' => $before,
            'expires_after' => $after,
            'applied_at' => now(),
        ])->save();
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
