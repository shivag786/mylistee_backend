<?php

namespace App\Http\Resources\Admin;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class AdminOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'type' => $this->type instanceof \App\Enums\OfferType ? $this->type->value : $this->type,
            'rewardValue' => $this->reward_value,
            'status' => $this->effectiveStatus(),
            'businessName' => $this->business?->name,
            'businessSlug' => $this->business?->slug,
            'startsAt' => $this->starts_at?->toDateString(),
            'endsAt' => $this->ends_at?->toDateString(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
