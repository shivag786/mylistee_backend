<?php

namespace App\Http\Resources;

use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A reward in the customer's wallet (document/phase/07 §Wallet). Exposes the
 * redemption `code` only to the owning customer's own wallet responses.
 *
 * @mixin Reward
 */
class RewardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effectiveStatus = $this->isExpired() && $this->status->value === 'active'
            ? 'expired'
            : $this->status->value;

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'title' => $this->title,
            'rewardValue' => $this->reward_value,
            'type' => $this->type,
            'status' => $effectiveStatus,
            'wonAt' => $this->won_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'redeemedAt' => $this->redeemed_at?->toIso8601String(),
            'business' => [
                'id' => $this->business?->uuid,
                'name' => $this->business?->name,
                'slug' => $this->business?->slug,
                'logoUrl' => $this->business?->logo_path
                    ? Storage::disk('public')->url($this->business->logo_path)
                    : null,
            ],
        ];
    }
}
