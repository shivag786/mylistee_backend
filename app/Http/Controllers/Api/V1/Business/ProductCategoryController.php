<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\ProductCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-business menu sections (Phase 7.2). Sections group the menu and feed the
 * combo builder (7.3).
 */
class ProductCategoryController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly ProductCategoryService $categories) {}

    /** GET /business/product-categories */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $categories = $business->productCategories()
            ->withCount('products')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(ProductCategoryResource::collection($categories), 'Menu sections retrieved.');
    }

    /** POST /business/product-categories */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $category = $this->categories->create($business, $validated['name']);

        return ApiResponse::success(new ProductCategoryResource($category), 'Menu section created.', status: 201);
    }

    /** PUT /business/product-categories/{uuid} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $category = $this->find($request, $uuid);
        if ($category === null) {
            return ApiResponse::error('Menu section not found.', status: 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $category = $this->categories->update($category, $validated['name']);

        return ApiResponse::success(new ProductCategoryResource($category), 'Menu section updated.');
    }

    /** DELETE /business/product-categories/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $category = $this->find($request, $uuid);
        if ($category === null) {
            return ApiResponse::error('Menu section not found.', status: 404);
        }

        $this->categories->delete($category);

        return ApiResponse::success(message: 'Menu section deleted.');
    }

    /** PATCH /business/product-categories/reorder */
    public function reorder(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string'],
        ]);

        $this->categories->reorder($business, $validated['order']);

        return $this->index($request);
    }

    private function find(Request $request, string $uuid): ?ProductCategory
    {
        return $this->business($request)->productCategories()->where('uuid', $uuid)->first();
    }
}
