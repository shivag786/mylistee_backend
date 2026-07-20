<?php

namespace App\Http\Resources;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Full business profile. Exposes the UUID (never the numeric id) and absolute
 * image URLs. Mirrors the frontend owner `Business` type.
 *
 * @mixin Business
 */
class BusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'ownerName' => $this->owner_name,
            'description' => $this->description,
            'category' => new BusinessCategoryResource($this->whenLoaded('category')),
            'logoUrl' => $this->url($this->logo_path),
            'coverUrl' => $this->url($this->cover_path),
            'address' => $this->address,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'openingTime' => $this->opening_time,
            'closingTime' => $this->closing_time,
            'isOpen' => $this->isOpenNow(),
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'whatsapp' => $this->whatsapp,
            'gst' => $this->gst,
            'status' => $this->status->value,
            'verified' => $this->verified,
            'featured' => $this->featured,
            'averageRating' => (float) $this->average_rating,
            'totalReviews' => $this->total_reviews,
            'gallery' => GalleryImageResource::collection($this->whenLoaded('gallery')),
            'qr' => new QrCodeResource($this->whenLoaded('qrCode')),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }

    private function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
