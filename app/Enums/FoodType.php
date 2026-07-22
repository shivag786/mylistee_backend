<?php

namespace App\Enums;

/**
 * Vegetarian classification for a product (PHASE 7.2 §Veg / Non-Veg). Optional —
 * relevant for food businesses, ignored by others.
 */
enum FoodType: string
{
    case Veg = 'veg';
    case NonVeg = 'non_veg';
    case Egg = 'egg';

    public function label(): string
    {
        return match ($this) {
            self::Veg => 'Veg',
            self::NonVeg => 'Non-veg',
            self::Egg => 'Egg',
        };
    }
}
