<?php

namespace App\Enums;

/**
 * Where a homepage banner shows. Extensible — add slots here and render them on
 * the homepage without touching the storage/admin layer.
 */
enum BannerPlacement: string
{
    case HomeTop = 'home_top';
    case HomeAfterCombos = 'home_after_combos';

    public function label(): string
    {
        return match ($this) {
            self::HomeTop => 'Top of home',
            self::HomeAfterCombos => 'After combos',
        };
    }
}
