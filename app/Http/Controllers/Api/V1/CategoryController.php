<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessCategoryResource;
use App\Models\BusinessCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    /**
     * Public list of active business categories, ordered for display.
     * GET /api/v1/categories. Cached (Milestone 15) — categories rarely change.
     */
    public function index(): JsonResponse
    {
        $categories = Cache::remember('categories.active', now()->addHours(6), fn () => BusinessCategory::where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());

        return ApiResponse::success(
            data: BusinessCategoryResource::collection($categories),
            message: 'Categories retrieved.',
        );
    }
}
