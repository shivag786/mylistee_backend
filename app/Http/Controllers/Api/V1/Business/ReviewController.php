<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\ReplyReviewRequest;
use App\Http\Resources\OwnerReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a business owner read, publicly reply to, and hide/show reviews on
 * their own business (Phase 1 — closes the review loop).
 */
class ReviewController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $reviews = Review::where('business_id', $business->id)
            ->with(['customer', 'shopCustomer', 'items'])
            ->latest()
            ->limit(100)
            ->get();

        return ApiResponse::success(OwnerReviewResource::collection($reviews));
    }

    public function reply(ReplyReviewRequest $request, string $uuid): JsonResponse
    {
        $review = $this->find($request, $uuid);
        if ($review === null) {
            return ApiResponse::error('Review not found.', status: 404);
        }

        $updated = $this->reviews->ownerReply($review, $request->validated('reply'));
        $updated->load(['shopCustomer', 'items']);

        return ApiResponse::success(new OwnerReviewResource($updated), 'Reply saved.');
    }

    /** PATCH /business/reviews/{uuid}/visibility { hidden: bool } */
    public function visibility(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['hidden' => ['required', 'boolean']]);

        $review = $this->find($request, $uuid);
        if ($review === null) {
            return ApiResponse::error('Review not found.', status: 404);
        }

        $hidden = (bool) $validated['hidden'];
        $updated = $this->reviews->setHiddenByOwner($review, $hidden);
        $updated->load(['customer', 'shopCustomer', 'items']);

        return ApiResponse::success(
            new OwnerReviewResource($updated),
            $hidden ? 'Review hidden from your page.' : 'Review is visible again.',
        );
    }

    private function find(Request $request, string $uuid): ?Review
    {
        return Review::where('uuid', $uuid)
            ->where('business_id', $this->business($request)->id)
            ->first();
    }
}
