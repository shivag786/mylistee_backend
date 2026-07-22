<?php

namespace App\Http\Resources;

use App\Enums\PromotionStatus;
use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->promotion_type instanceof PromotionType ? $this->promotion_type : null;
        $status = $this->status instanceof PromotionStatus ? $this->status->value : $this->status;
        $config = $this->config ?? [];

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'type' => $type?->value ?? $this->promotion_type,
            'typeLabel' => $type?->label(),
            'status' => $status,
            'productId' => $this->whenLoaded('product', fn () => $this->product?->uuid),
            'productName' => $this->whenLoaded('product', fn () => $this->product?->name),
            'discountType' => $config['discount_type'] ?? null,
            'value' => isset($config['value']) ? (float) $config['value'] : null,
            'buyQty' => $config['buy_qty'] ?? null,
            'getQty' => $config['get_qty'] ?? null,
            'minQty' => $config['min_qty'] ?? null,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'dailyStartTime' => $this->daily_start_time,
            'dailyEndTime' => $this->daily_end_time,
            'autoStart' => (bool) $this->auto_start,
            'autoStop' => (bool) $this->auto_stop,
            'priority' => (int) $this->priority,
            'isActiveNow' => $this->isActiveNow(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
