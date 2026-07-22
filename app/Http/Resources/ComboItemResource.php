<?php

namespace App\Http\Resources;

use App\Models\ComboItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin ComboItem
 */
class ComboItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;

        return [
            'productId' => $product?->uuid,
            'name' => $product?->name,
            'imageUrl' => $product?->image_path ? Storage::disk('public')->url($product->image_path) : null,
            'sellingPrice' => $product !== null ? (float) $product->selling_price : null,
            'quantity' => (int) $this->quantity,
        ];
    }
}
