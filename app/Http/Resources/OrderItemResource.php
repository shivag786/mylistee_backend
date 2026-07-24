<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'itemType' => $this->item_type,
            'unitPrice' => (float) $this->unit_price,
            'quantity' => (int) $this->quantity,
            'lineTotal' => $this->lineTotal(),
        ];
    }
}
