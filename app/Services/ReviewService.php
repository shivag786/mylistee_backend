<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Models\Business;
use App\Models\Order;
use App\Models\Review;
use App\Models\ShopCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

    /**
     * Create or update a review left through the shop's public review link.
     * The reviewer is identified only by mobile (no OTP), so one review per
     * mobile per shop: resubmitting edits it rather than piling up. An edit
     * never touches moderation — a review an admin or the owner hid stays
     * hidden. No coins: there is no account to credit.
     *
     * @param  list<array{type: string, id: int, name: string, rating: int}>  $items
     */
    public function upsertFromLink(
        Business $business,
        ShopCustomer $reviewer,
        ?string $name,
        ?int $rating,
        ?string $comment,
        array $items,
    ): Review {
        $review = DB::transaction(function () use ($business, $reviewer, $name, $rating, $comment, $items): Review {
            $review = Review::firstOrNew([
                'business_id' => $business->id,
                'shop_customer_id' => $reviewer->id,
            ]);
            if (! $review->exists) {
                $review->status = 'published';
            }
            $review->fill([
                // Snapshotted so the review keeps its author if the owner later
                // deletes the customer from their book.
                'reviewer_name' => $name ?? $reviewer->name,
                'rating' => $rating,
                'comment' => $comment,
            ])->save();

            $review->items()->delete();
            foreach ($items as $item) {
                $review->items()->create([
                    'item_type' => $item['type'],
                    'product_id' => $item['type'] === 'product' ? $item['id'] : null,
                    'combo_id' => $item['type'] === 'combo' ? $item['id'] : null,
                    'name' => $item['name'],
                    'rating' => $item['rating'],
                ]);
            }

            return $review;
        });

        $business->recalculateRating();

        return $review->fresh(['shopCustomer', 'items']);
    }

    /**
     * Owner shows or hides a review on their public page. Hidden reviews still
     * count toward the shop's rating — hiding is about what's displayed, not a
     * way to drop low scores from the average.
     */
    public function setHiddenByOwner(Review $review, bool $hidden): Review
    {
        $review->update(['hidden_by_owner_at' => $hidden ? ($review->hidden_by_owner_at ?? now()) : null]);

        return $review;
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
