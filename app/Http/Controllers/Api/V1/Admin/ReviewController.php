<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminReviewResource;
use App\Models\Review;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Review moderation for the Super Admin (document/phase/14 §Review Moderation).
 * Hiding a review recomputes the business's cached rating.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** GET /admin/reviews */
    public function index(Request $request): JsonResponse
    {
        $query = Review::query()
            ->with(['business:id,name,slug', 'customer:id,name'])
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id');

        $page = $query->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            AdminReviewResource::collection($page->getCollection()),
            'Reviews retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /** PATCH /admin/reviews/{uuid}/status — published / hidden. */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['published', 'hidden'])],
        ]);

        $review = Review::where('uuid', $uuid)->first();
        if ($review === null) {
            return ApiResponse::error('Review not found.', status: 404);
        }

        $review->update(['status' => $validated['status']]);
        // Hidden reviews must drop out of the business's rating.
        $review->business?->recalculateRating();

        $this->audit->log($request->user(), 'review.status', $review, "Set status to {$validated['status']}");

        return ApiResponse::success(
            new AdminReviewResource($review->fresh(['business', 'customer'])),
            'Review updated.',
        );
    }
}
