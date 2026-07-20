<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Review;
use App\Models\User;

/**
 * Customer reviews (document/phase/02 §Reviews). One review per customer per
 * business; the business's cached rating is recomputed on every change.
 */
class ReviewService
{
    /** Create or update the customer's review for a business. */
    public function upsert(User $user, Business $business, int $rating, ?string $comment): Review
    {
        $review = Review::updateOrCreate(
            ['business_id' => $business->id, 'customer_id' => $user->id],
            ['rating' => $rating, 'comment' => $comment, 'status' => 'published'],
        );

        $business->recalculateRating();

        return $review->fresh('customer');
    }

    public function delete(Review $review): void
    {
        $business = $review->business;
        $review->delete();
        $business?->recalculateRating();
    }
}
