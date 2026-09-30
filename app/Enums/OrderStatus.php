<?php

namespace App\Enums;

/**
 * Order lifecycle (Phase 7.5). Placed by the customer → confirmed by the owner →
 * marked paid (cash/UPI at the counter) → completed. Cancellable until paid.
 *
 * An order paid online starts one step earlier, in AwaitingPayment, and only
 * becomes Placed once Razorpay confirms the money. Until then the shop never
 * sees it: it is not in active(), so it stays out of the owner's queue, and a
 * customer who closes the payment window leaves no half-order behind for the
 * kitchen to start on.
 */
enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            default => ucfirst($this->value),
        };
    }

    /** Statuses that still need the owner's attention (drive the "new orders" list). */
    public static function active(): array
    {
        return [self::Placed, self::Confirmed, self::Paid];
    }
}
