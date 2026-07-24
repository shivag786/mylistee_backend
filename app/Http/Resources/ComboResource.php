<?php

namespace App\Http\Resources;

use App\Models\Combo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Combo
 */
class ComboResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'imageUrl' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'comboPrice' => (float) $this->combo_price,
            'totalPrice' => $this->totalPrice(),
            'totalMrp' => $this->totalMrp(),
            'savings' => $this->savings(),
            'coinsEarned' => $this->coins_earned,
            'walletCoinsAccepted' => (bool) $this->wallet_coins_accepted,
            'coinsAccepted' => $this->coins_accepted !== null ? (int) $this->coins_accepted : 0,
            'nextVisitCoupon' => $this->next_visit_coupon,
            'bonusReward' => $this->bonus_reward,
            'items' => ComboItemResource::collection($this->whenLoaded('items')),
            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'autoEnable' => (bool) $this->auto_enable,
            'autoDisable' => (bool) $this->auto_disable,
            'isVisible' => (bool) $this->is_visible,
            'isActiveNow' => $this->isActiveNow(),
            'position' => (int) $this->position,
        ];
    }
}
