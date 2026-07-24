<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof OrderStatus ? $this->status->value : $this->status;

        return [
            'id' => $this->uuid,
            'token' => $this->token,
            'status' => $status,
            'subtotal' => (float) $this->subtotal,
            'coinsUsed' => (int) $this->coins_used,
            'coinDiscount' => (float) $this->coin_discount,
            'total' => (float) $this->total,
            'coinsEarned' => (int) $this->coins_earned,
            'note' => $this->note,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'itemCount' => $this->whenLoaded('items', fn () => $this->items->sum('quantity')),
            'businessName' => $this->whenLoaded('business', fn () => $this->business?->name),
            'customerName' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'placedAt' => $this->placed_at?->toIso8601String(),
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
