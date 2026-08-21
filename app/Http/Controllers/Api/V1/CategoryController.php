<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessCategoryResource;
use App\Models\BusinessCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Public list of active business categories, ordered for display.
     * GET /api/v1/categories. Uncached — an admin toggling a category's
     * visibility should see it take effect immediately.
     */
    public function index(): JsonResponse
    {
        $categories = BusinessCategory::where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            data: BusinessCategoryResource::collection($categories),
            message: 'Categories retrieved.',
        );
    }
}
