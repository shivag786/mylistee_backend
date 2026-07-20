<?php

namespace App\Http\Resources\Admin;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
class AdminReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'status' => $this->status,
            'customerName' => $this->customer?->name,
            'businessName' => $this->business?->name,
            'businessSlug' => $this->business?->slug,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
