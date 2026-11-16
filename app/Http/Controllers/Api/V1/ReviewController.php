<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Business;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Services\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Business reviews (document/phase/11 §Review Endpoints). Listing is public;
 * writing requires a signed-in customer (one review per business).
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /** GET /businesses/{slug}/reviews — public, newest first. */
    public function index(string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)->firstOrFail();

        $reviews = $business->reviews()
            ->where('status', 'published')
            ->whereNull('hidden_by_owner_at')
            ->with(['customer', 'shopCustomer', 'items'])
            ->latest()
            ->get();

        return ApiResponse::success(ReviewResource::collection($reviews), 'Reviews.');
    }

    /**
     * POST /reviews { orderId?, businessSlug?, rating, comment } — create/update own
     * review. Verified purchase: the customer must have a paid/completed order at
     * the shop, and the review is linked to that order.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'orderId' => ['nullable', 'string'],
            'businessSlug' => ['nullable', 'string', Rule::exists('businesses', 'slug')],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $fulfilled = [OrderStatus::Paid->value, OrderStatus::Completed->value];

        if (! empty($data['orderId'])) {
            $order = $user->orders()->whereIn('status', $fulfilled)->where('uuid', $data['orderId'])->first();
            if ($order === null) {
                return ApiResponse::error('You can only review an order you have completed.', status: 403);
            }
            $business = $order->business;
        } elseif (! empty($data['businessSlug'])) {
            $business = Business::where('slug', $data['businessSlug'])->firstOrFail();
            $order = $user->orders()
                ->where('business_id', $business->id)
                ->whereIn('status', $fulfilled)
                ->latest('id')
                ->first();
            if ($order === null) {
                return ApiResponse::error('Only customers who have ordered here can leave a review.', status: 403);
            }
        } else {
            return ApiResponse::error('An order is required to leave a review.', status: 422);
        }

        $review = $this->reviews->upsert($user, $business, $data['rating'], $data['comment'] ?? null, $order);

        return ApiResponse::success(new ReviewResource($review), 'Thanks for your review!', status: 201);
    }

    /**
     * POST /reviews/{uuid}/report { reason, note? } — flag someone else's review
     * for the admin. Reporting the same review again just updates the reason.
     */
    public function report(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(ReviewReport::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $review = Review::where('uuid', $uuid)->first();
        if ($review === null) {
            return ApiResponse::error('Review not found.', status: 404);
        }

        $user = $request->user();
        if ($review->customer_id !== null && $review->customer_id === $user->id) {
            return ApiResponse::error('You can\'t report your own review.', status: 422);
        }

        ReviewReport::updateOrCreate(
            ['review_id' => $review->id, 'reporter_id' => $user->id],
            ['reason' => $data['reason'], 'note' => $data['note'] ?? null],
        );

        return ApiResponse::success(message: 'Thanks. We\'ll look at this review.');
    }

    /** DELETE /reviews/{uuid} — remove the customer's own review. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $review = Review::where('uuid', $uuid)
            ->where('customer_id', $request->user()->id)
            ->first();

        if ($review === null) {
            return ApiResponse::error('Review not found.', status: 404);
        }

        $this->reviews->delete($review);

        return ApiResponse::success(message: 'Review removed.');
    }
}
