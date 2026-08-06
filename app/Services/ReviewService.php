<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Models\Business;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;

/**
 * Customer reviews (document/phase/02 §Reviews). Reviews are a verified purchase:
 * a customer can only review a shop they have a paid/completed order with, and the
 * review is linked to that order. One editable review per customer per business
 * (so a repeat customer can't skew the average); the cached rating is recomputed
 * on every change.
 */
class ReviewService
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /** Create or update the customer's review for a business, tied to a verifying order. */
    public function upsert(User $user, Business $business, int $rating, ?string $comment, ?Order $order = null): Review
    {
        $review = Review::updateOrCreate(
            ['business_id' => $business->id, 'customer_id' => $user->id],
            [
                'order_id' => $order?->id,
                'rating' => $rating,
                'comment' => $comment,
                'status' => 'published',
            ],
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
