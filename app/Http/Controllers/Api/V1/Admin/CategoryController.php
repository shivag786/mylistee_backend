<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminCategoryResource;
use App\Models\BusinessCategory;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master category management for the Super Admin (Phase 7.1). Categories are the
 * single reference list used across the platform; changes bust the public cache
 * via the service.
 */
class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/categories */
    public function index(): JsonResponse
    {
        $categories = BusinessCategory::withCount('businesses')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            AdminCategoryResource::collection($categories),
            'Categories retrieved.',
        );
    }

    /** POST /admin/categories */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);

        $category = $this->categories->create($validated, $request->file('image'));
        $this->audit->log($request->user(), 'category.create', $category, "Created category {$category->name}");

        return ApiResponse::success(
            new AdminCategoryResource($category->loadCount('businesses')),
            'Category created.',
            status: 201,
        );
    }

    /** PUT /admin/categories/{uuid} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $category = BusinessCategory::where('uuid', $uuid)->first();
        if ($category === null) {
            return ApiResponse::error('Category not found.', status: 404);
        }

        $validated = $this->validateData($request, $category);
        $category = $this->categories->update($category, $validated, $request->file('image'));
        $this->audit->log($request->user(), 'category.update', $category, "Updated category {$category->name}");

        return ApiResponse::success(
            new AdminCategoryResource($category->loadCount('businesses')),
            'Category updated.',
        );
    }

    /** DELETE /admin/categories/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $category = BusinessCategory::where('uuid', $uuid)->first();
        if ($category === null) {
            return ApiResponse::error('Category not found.', status: 404);
        }

        $this->categories->delete($category);
        $this->audit->log($request->user(), 'category.delete', $category, "Deleted category {$category->name}");

        return ApiResponse::success(message: 'Category deleted.');
    }

    /** PATCH /admin/categories/reorder */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string', 'exists:business_categories,uuid'],
        ]);

        $this->categories->reorder($validated['order']);

        return $this->index();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?BusinessCategory $existing = null): array
    {
        $slugRule = Rule::unique('business_categories', 'slug');
        if ($existing !== null) {
            $slugRule = $slugRule->ignore($existing->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'alpha_dash', $slugRule],
            'icon' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'alt_text' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'show_on_homepage' => ['nullable', 'boolean'],
            'show_in_search' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);
    }
}
