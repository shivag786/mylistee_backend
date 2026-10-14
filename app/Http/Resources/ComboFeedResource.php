<?php

namespace App\Http\Resources;

use App\Models\Combo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A combo for the home "Meal combos" row — the combo plus a compact reference to
 * the shop it belongs to (for the card label and deep-link to the profile).
 *
 * @mixin Combo
 */
class ComboFeedResource extends JsonResource
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
            'savings' => $this->savings(),
            // Drives the customer countdown; only meaningful when it auto-hides.
            'endsAt' => $this->auto_disable ? $this->ends_at?->toIso8601String() : null,
            'items' => ComboItemResource::collection($this->whenLoaded('items')),
            'business' => [
                'slug' => $this->business->slug,
                'name' => $this->business->name,
                'logo' => $this->business->logo_path ? Storage::disk('public')->url($this->business->logo_path) : null,
                // Set by FeedRanker: null without the viewer's location.
                'distanceMeters' => $this->getAttribute('feed_distance_meters'),
                'isFollowed' => (bool) $this->getAttribute('feed_is_followed'),
            ],
        ];
    }
}
