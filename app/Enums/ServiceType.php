<?php

namespace App\Enums;

/**
 * How an order is served. Additive layer over the Phase 7.5 order flow so one
 * order system fits every business category: a bakery only ever uses Pickup,
 * while a restaurant/hotel adds DineIn (optionally bound to a table), Takeaway,
 * and Delivery. The order lifecycle, token, and coins are identical for all.
 */
enum ServiceType: string
{
    case Pickup = 'pickup';
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Pickup',
            self::DineIn => 'Dine-in',
            self::Takeaway => 'Takeaway',
            self::Delivery => 'Delivery',
        };
    }

    /** The default mode every business supports (bakery-style counter pickup). */
    public static function default(): self
    {
        return self::Pickup;
    }

    /** @return array<int, string> every mode's value — for validation `Rule::in`. */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
