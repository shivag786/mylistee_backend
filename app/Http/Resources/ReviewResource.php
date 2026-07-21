<?php

namespace App\Http\Resources;

use App\Models\Review;
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
            'rating' => $this->rating,
            'comment' => $this->comment,
            'reply' => $this->reply,
            'repliedAt' => $this->replied_at?->toIso8601String(),
            'customerName' => $this->customer?->name,
            'isMine' => $currentUser !== null && $currentUser->id === $this->customer_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
