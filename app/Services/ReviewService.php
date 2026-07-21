<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Models\Business;
use App\Models\Review;
use App\Models\User;

/**
 * Customer reviews (document/phase/02 §Reviews). One review per customer per
 * business; the business's cached rating is recomputed on every change.
 */
class ReviewService
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /** Create or update the customer's review for a business. */
    public function upsert(User $user, Business $business, int $rating, ?string $comment): Review
    {
        $review = Review::updateOrCreate(
            ['business_id' => $business->id, 'customer_id' => $user->id],
            ['rating' => $rating, 'comment' => $comment, 'status' => 'published'],
        );

        $business->recalculateRating();

        // Reward the first review a customer leaves for this business (editing it
        // later doesn't re-earn).
        if ($review->wasRecentlyCreated) {
            $this->loyalty->awardOnce($user, CoinSource::Review, $business, ['reference' => $review]);
        }

        return $review->fresh('customer');
    }

    public function delete(Review $review): void
    {
        $business = $review->business;
        $review->delete();
        $business?->recalculateRating();
    }

    /** Owner's public reply to a customer review (empty reply clears it). */
    public function ownerReply(Review $review, ?string $reply): Review
    {
        $reply = $reply !== null ? trim($reply) : null;

        $review->update([
            'reply' => $reply ?: null,
            'replied_at' => $reply ? now() : null,
        ]);

        return $review->fresh('customer');
    }
}
