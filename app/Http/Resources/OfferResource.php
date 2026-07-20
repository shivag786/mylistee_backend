<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Offer
 */
class OfferResource extends JsonResource
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
            'type' => $this->type->value,
            'typeLabel' => $this->type->label(),
            'rewardValue' => $this->reward_value,
            'imageUrl' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'startsAt' => $this->starts_at?->toDateString(),
            'endsAt' => $this->ends_at?->toDateString(),
            'totalQuantity' => $this->total_quantity,
            'remainingQuantity' => $this->remaining_quantity,
            'weight' => $this->weight,
            'priority' => $this->priority,
            'status' => $this->effectiveStatus(),
            'isArchived' => $this->status->value === 'archived',
            'isLive' => $this->isLive(),
            'premiumOnly' => $this->premium_only,
            'visibility' => $this->visibility,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
