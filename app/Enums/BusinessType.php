<?php

namespace App\Enums;

/**
 * What kind of shop a category is. It decides which tools the vendor app
 * shows: the kitchen screen for food and bakery, veg marks for food, and so
 * on. Set per category by the admin.
 */
enum BusinessType: string
{
    case Food = 'food';
    case Bakery = 'bakery';
    case Retail = 'retail';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Food & drinks',
            self::Bakery => 'Bakery & sweets',
            self::Retail => 'Retail shop',
            self::Service => 'Services',
        };
    }

    /** Orders are made to order, so they go through a kitchen. */
    public function usesKitchen(): bool
    {
        return $this === self::Food || $this === self::Bakery;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
