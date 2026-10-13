<?php

namespace App\Http\Resources;

use App\Models\Review;
use App\Models\ReviewItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentUser = $request->user('sanctum');

        return [
            'id' => $this->uuid,
            // Null when a review-link reviewer skipped the overall star.
            'rating' => $this->rating,
            'comment' => $this->comment,
            'reply' => $this->reply,
            'repliedAt' => $this->replied_at?->toIso8601String(),
            'customerName' => $this->authorName(),
            // App reviews require a fulfilled order; review-link ones don't.
            'isVerified' => $this->customer_id !== null,
            'isMine' => $currentUser !== null && $this->customer_id !== null && $currentUser->id === $this->customer_id,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (ReviewItem $item) => [
                'type' => $item->item_type,
                'name' => $item->name,
                'rating' => $item->rating,
            ])->values()->all(), []),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
