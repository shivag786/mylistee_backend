<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A single product deal for the home "Today's Deals" row — the discounted
 * product plus a compact reference to the shop it belongs to (for the card
 * label and the deep-link to the profile). Reuses the Product model's promotion
 * resolution so the effective price matches everywhere else.
 *
 * @mixin Product
 */
class DealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $selling = (float) $this->selling_price;
        $effective = $this->effectivePrice();
        $mrp = $this->mrp !== null ? (float) $this->mrp : null;
        // Any active promotion (incl. BOGO / quantity, which don't change price).
        $promotion = $this->activeDisplayPromotion();

        // Discount vs the normal selling price (0 for non-unit-price offers).
        $offPercent = $selling > 0 ? (int) round(($selling - $effective) / $selling * 100) : 0;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'imageUrl' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'sellingPrice' => $selling,
            'mrp' => $mrp,
            'effectivePrice' => $effective,
            'discountPercent' => $offPercent,
            'offer' => $promotion === null ? null : [
                'name' => $promotion->name,
                'type' => $promotion->promotion_type->value,
                'typeLabel' => $promotion->promotion_type->label(),
                'label' => $promotion->displayLabel(),
                'endsAt' => $promotion->ends_at?->toIso8601String(),
            ],
            'business' => [
                'slug' => $this->business->slug,
                'name' => $this->business->name,
                'logo' => $this->business->logo_path ? Storage::disk('public')->url($this->business->logo_path) : null,
                'area' => $this->business->address,
            ],
        ];
    }
}
