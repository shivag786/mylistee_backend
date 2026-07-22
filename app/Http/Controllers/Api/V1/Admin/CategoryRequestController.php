<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryRequestResource;
use App\Models\CategoryRequest;
use App\Services\AuditService;
use App\Services\CategoryRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin moderation queue for owner-submitted category requests (Phase 7.1).
 */
class CategoryRequestController extends Controller
{
    public function __construct(
        private readonly CategoryRequestService $requests,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/category-requests?status=pending */
    public function index(Request $request): JsonResponse
    {
        $query = CategoryRequest::query()
            ->with(['requester:id,name', 'business:id,name'])
            ->when(
                $request->string('status')->trim()->value(),
                fn ($q, $status) => $q->where('status', $status),
            )
            ->latest('id');

        $page = $query->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            CategoryRequestResource::collection($page->getCollection()),
            'Category requests retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /** PATCH /admin/category-requests/{uuid}/approve */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        $categoryRequest = $this->find($uuid);
        if ($categoryRequest === null) {
            return ApiResponse::error('Request not found.', status: 404);
        }

        $categoryRequest = $this->requests->approve($categoryRequest, $request->user());
        $this->audit->log($request->user(), 'category-request.approve', $categoryRequest, "Approved category request {$categoryRequest->name}");

        return ApiResponse::success(
            new CategoryRequestResource($categoryRequest->load(['requester', 'business'])),
            'Category request approved.',
        );
    }

    /** PATCH /admin/category-requests/{uuid}/reject */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $categoryRequest = $this->find($uuid);
        if ($categoryRequest === null) {
            return ApiResponse::error('Request not found.', status: 404);
        }

        $categoryRequest = $this->requests->reject($categoryRequest, $request->user(), $validated['note'] ?? null);
        $this->audit->log($request->user(), 'category-request.reject', $categoryRequest, "Rejected category request {$categoryRequest->name}");

        return ApiResponse::success(
            new CategoryRequestResource($categoryRequest->load(['requester', 'business'])),
            'Category request rejected.',
        );
    }

    private function find(string $uuid): ?CategoryRequest
    {
        return CategoryRequest::where('uuid', $uuid)->first();
    }
}
