<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\ReplyReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lets a business owner read and publicly reply to reviews on their own
 * business (Phase 1 — closes the review loop; owners were unable to respond).
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business()->firstOrFail();

        $reviews = Review::where('business_id', $business->id)
            ->with('customer')
            ->latest()
            ->limit(100)
            ->get();

        return ApiResponse::success(ReviewResource::collection($reviews));
    }

    public function reply(ReplyReviewRequest $request, string $uuid): JsonResponse
    {
        $business = $request->user()->business()->firstOrFail();

        $review = Review::where('uuid', $uuid)
            ->where('business_id', $business->id)
            ->first();

        if ($review === null) {
            throw new NotFoundHttpException('Review not found.');
        }

        $updated = $this->reviews->ownerReply($review, $request->validated('reply'));

        return ApiResponse::success(new ReviewResource($updated), 'Reply saved.');
    }
}
