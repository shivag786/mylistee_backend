<?php

namespace App\Enums;

/**
 * Order lifecycle (Phase 7.5). Placed by the customer → confirmed by the owner →
 * marked paid (cash/UPI at the counter) → completed. Cancellable until paid.
 */
enum OrderStatus: string
{
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Statuses that still need the owner's attention (drive the "new orders" list). */
    public static function active(): array
    {
        return [self::Placed, self::Confirmed, self::Paid];
    }
}
