<?php

namespace App\Http\Resources;

use App\Enums\FoodType;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mrp = $this->mrp !== null ? (float) $this->mrp : null;
        $selling = (float) $this->selling_price;
        $foodType = $this->food_type instanceof FoodType ? $this->food_type->value : $this->food_type;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'ingredients' => $this->ingredients,
            'imageUrl' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'gallery' => ProductImageResource::collection($this->whenLoaded('images')),
            'categoryId' => $this->whenLoaded('category', fn () => $this->category?->uuid),
            'categoryName' => $this->whenLoaded('category', fn () => $this->category?->name),
            'mrp' => $mrp,
            'sellingPrice' => $selling,
            'discountPercent' => $mrp !== null && $mrp > $selling ? (int) round(($mrp - $selling) / $mrp * 100) : 0,
            // Effective price after the best active promotion (Phase 7.2b) — only
            // when promotions are loaded, so the base resource stays cheap.
            'effectivePrice' => $this->whenLoaded('promotions', fn () => $this->effectivePrice()),
            'activeOffer' => $this->whenLoaded('promotions', function () {
                $promotion = $this->activePromotion();

                return $promotion === null ? null : [
                    'id' => $promotion->uuid,
                    'name' => $promotion->name,
                    'type' => $promotion->promotion_type->value,
                ];
            }),
            'foodType' => $foodType,
            'availableFrom' => $this->available_from,
            'availableTo' => $this->available_to,
            'prepMinutes' => $this->prep_minutes,
            'isTodaysSpecial' => (bool) $this->is_todays_special,
            'isBestseller' => (bool) $this->is_bestseller,
            'isRecommended' => (bool) $this->is_recommended,
            'inStock' => (bool) $this->in_stock,
            'isVisible' => (bool) $this->is_visible,
            'position' => (int) $this->position,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
