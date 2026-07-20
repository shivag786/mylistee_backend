<?php

namespace App\Http\Resources\Admin;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Business
 */
class AdminBusinessResource extends JsonResource
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
            'ownerName' => $this->owner?->name,
            'ownerEmail' => $this->owner?->email,
            // Login credentials for the owner (demo — pin_plain is plaintext).
            'ownerMobile' => $this->owner?->phone,
            'ownerPin' => $this->owner?->pin_plain,
            'category' => $this->category?->name,
            'logoUrl' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'status' => $this->status instanceof \App\Enums\BusinessStatus ? $this->status->value : $this->status,
            'verified' => (bool) $this->verified,
            'featured' => (bool) $this->featured,
            'averageRating' => (float) $this->average_rating,
            'totalReviews' => (int) $this->total_reviews,
            'totalVisits' => (int) $this->total_visits,
            'totalSpins' => (int) $this->total_spins,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
