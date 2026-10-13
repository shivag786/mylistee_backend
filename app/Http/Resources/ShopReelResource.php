<?php

namespace App\Http\Resources;

use App\Models\ShopReel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A reel for the owner's list and the public feed. The shop block carries the
 * FeedRanker chips (distance, following) when the reel came from a ranked feed.
 *
 * @mixin ShopReel
 */
class ShopReelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $url = fn (?string $path) => $path ? Storage::disk('public')->url($path) : null;
        $product = $this->product;

        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'videoUrl' => $url($this->video_path),
            'posterUrl' => $url($this->poster_path) ?? $url($this->photo_paths[0] ?? null),
            'photoUrls' => array_values(array_map($url, $this->photo_paths ?? [])),
            'caption' => $this->caption,
            'durationSeconds' => $this->duration_seconds,
            'isVisible' => $this->is_visible,
            'views' => $this->views,
            'dish' => $product === null ? null : [
                'id' => $product->uuid,
                'name' => $product->name,
                'price' => round($product->effectivePrice(), 2),
                'strikePrice' => $product->effectivePrice() < (float) $product->selling_price ? (float) $product->selling_price : null,
                'imageUrl' => $url($product->image_path),
            ],
            'business' => $this->whenLoaded('business', fn () => [
                'slug' => $this->business->slug,
                'name' => $this->business->name,
                'logoUrl' => $url($this->business->logo_path),
                'distanceMeters' => $this->getAttribute('feed_distance_meters'),
                'isFollowed' => (bool) $this->getAttribute('feed_is_followed'),
            ]),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
