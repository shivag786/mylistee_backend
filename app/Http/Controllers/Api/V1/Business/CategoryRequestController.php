<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryRequestResource;
use App\Models\CategoryRequest;
use App\Services\CategoryRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business owners request a new master category when none fits (Phase 7.1).
 * The submission is queued for admin approval.
 */
class CategoryRequestController extends Controller
{
    public function __construct(private readonly CategoryRequestService $requests) {}

    /** GET /business/category-requests — the owner's own requests. */
    public function index(Request $request): JsonResponse
    {
        $requests = CategoryRequest::where('requested_by', $request->user()->id)
            ->latest('id')
            ->get();

        return ApiResponse::success(
            CategoryRequestResource::collection($requests),
            'Your category requests.',
        );
    }

    /** POST /business/category-requests */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sample_image' => ['nullable', 'image', 'max:4096'],
        ]);

        $business = $request->user()->businesses()->first();

        $categoryRequest = $this->requests->request(
            $request->user(),
            $validated['name'],
            $business,
            $request->file('sample_image'),
        );

        return ApiResponse::success(
            new CategoryRequestResource($categoryRequest),
            'Category request submitted. We\'ll review it shortly.',
            status: 201,
        );
    }
}
