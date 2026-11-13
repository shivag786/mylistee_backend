<?php

namespace App\Enums;

/**
 * How an order was paid at the counter (Phase 7.5). Recorded when the owner
 * marks an order paid — no gateway is involved; "online" means the customer
 * paid by UPI/card directly to the shop, "cod" means cash on delivery/pickup.
 */
enum PaymentMethod: string
{
    case Cod = 'cod';
    case Online = 'online';
    // Counter split for the day-end cash count: UPI and card apart from cash.
    case Upi = 'upi';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash',
            self::Online => 'Online',
            self::Upi => 'UPI',
            self::Card => 'Card',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
