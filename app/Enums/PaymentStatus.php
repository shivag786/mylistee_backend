<?php

namespace App\Enums;

/**
 * Lifecycle of a Razorpay payment attempt for a business plan.
 *
 * Mirrors Razorpay's own payment states so a support agent can compare a row
 * here against the Razorpay dashboard without a translation table:
 *
 *   created    → order created, the owner has not paid yet (or abandoned Checkout)
 *   authorized → funds held but not yet captured (manual-capture flows)
 *   captured   → money settled — this is the only state that activates a plan
 *   failed     → the attempt failed (card declined, UPI timeout, user closed)
 *   refunded   → captured, then refunded in part or full
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /** Has the money actually landed? Only then may a subscription go live. */
    public function isPaid(): bool
    {
        return $this === self::Captured;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Captured, self::Failed, self::Refunded], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
