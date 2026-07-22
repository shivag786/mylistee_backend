<?php

namespace App\Enums;

/**
 * Promotion types handled by the one engine (07A). New types can be appended
 * without a schema change — type-specific fields live in `promotions.config`.
 * Combo/wallet/coupon/etc. join later phases; this set covers product discounts
 * (Phase 7.2b, PHASE 7.2 §Discount Module).
 */
enum PromotionType: string
{
    case Percentage = 'percentage';
    case Flat = 'flat';
    case HappyHour = 'happy_hour';
    case FlashSale = 'flash_sale';
    case Weekend = 'weekend';
    case Festival = 'festival';
    case Bogo = 'bogo';                    // buy X get Y
    case QuantityDiscount = 'quantity_discount';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage discount',
            self::Flat => 'Flat discount',
            self::HappyHour => 'Happy hour',
            self::FlashSale => 'Flash sale',
            self::Weekend => 'Weekend offer',
            self::Festival => 'Festival offer',
            self::Bogo => 'Buy X get Y',
            self::QuantityDiscount => 'Quantity discount',
        };
    }

    /**
     * Whether this type reduces a product's unit price (vs cart-level offers like
     * BOGO / quantity discounts that don't change the shelf price).
     */
    public function affectsUnitPrice(): bool
    {
        return ! in_array($this, [self::Bogo, self::QuantityDiscount], true);
    }
}
