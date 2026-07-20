<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Slim public view of an offer for the customer-facing spinner + profile.
 * Deliberately omits weight/quantity so win probabilities are never exposed
 * (document/phase/02 §Spinner Rules — "never appear unfair").
 *
 * @mixin Offer
 */
class PublicOfferResource extends JsonResource
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
        ];
    }
}
