<?php

namespace App\Http\Resources;

use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reward from the business owner's perspective (verify + history). Shows the
 * customer's display name; owner never sees the customer's email/phone.
 *
 * @mixin Reward
 */
class RedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'title' => $this->title,
            'rewardValue' => $this->reward_value,
            'status' => $this->status->value,
            'customerName' => $this->customer?->name,
            'wonAt' => $this->won_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'redeemedAt' => $this->redeemed_at?->toIso8601String(),
        ];
    }
}
