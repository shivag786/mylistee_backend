<?php

namespace App\Http\Resources;

use App\Models\LoyaltyReward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoyaltyReward
 */
class LoyaltyRewardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'coinsCost' => $this->coins_cost,
            'rewardValue' => $this->reward_value,
            'active' => $this->active,
            'stock' => $this->stock,
            'sortOrder' => $this->sort_order,
            'available' => $this->isAvailable(),
        ];
    }
}
