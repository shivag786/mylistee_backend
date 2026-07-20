<?php

namespace App\Http\Resources;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Compact business for discovery lists (home / nearby / search). Mirrors the
 * frontend `Business` card type.
 *
 * @mixin Business
 */
class BusinessListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category?->name,
            'area' => $this->address,
            'coverImage' => $this->url($this->cover_path),
            'logo' => $this->url($this->logo_path),
            'rating' => (float) $this->average_rating,
            'reviewCount' => $this->total_reviews,
            'offerCount' => (int) ($this->offer_count ?? 0),
            'distanceMeters' => $this->getAttribute('distance_meters'),
            'isOpen' => $this->isOpenNow(),
            'isFavorite' => (bool) $this->getAttribute('is_favorite'),
            'status' => $this->status->value,
        ];
    }

    private function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
