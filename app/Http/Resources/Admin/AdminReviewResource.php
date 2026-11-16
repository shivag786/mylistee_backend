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
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->status,
            'customerName' => $this->authorName(),
            'businessName' => $this->business?->name,
            'businessSlug' => $this->business?->slug,
            // Customers' flags (Report review in the app).
            'reportsCount' => (int) ($this->reports_count ?? 0),
            'reportReasons' => $this->whenLoaded(
                'reports',
                fn () => $this->reports->pluck('reason')->countBy()->all(),
                [],
            ),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
