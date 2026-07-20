<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Business;
use App\Models\Review;
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
            ->with('customer')
            ->latest()
            ->get();

        return ApiResponse::success(ReviewResource::collection($reviews), 'Reviews.');
    }

    /** POST /reviews { businessSlug, rating, comment } — create/update own review. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'businessSlug' => ['required', 'string', Rule::exists('businesses', 'slug')],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $business = Business::where('slug', $data['businessSlug'])->firstOrFail();
        $review = $this->reviews->upsert($request->user(), $business, $data['rating'], $data['comment'] ?? null);

        return ApiResponse::success(new ReviewResource($review), 'Thanks for your review!', status: 201);
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
