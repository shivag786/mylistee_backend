<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\FoodType;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product catalogue CRUD for the business owner (Phase 7.2). Every action is
 * scoped to the owner's own business. Multipart create/update (image upload);
 * updates use POST + `_method=PUT` spoofing (PHP can't parse multipart on PUT).
 */
class ProductController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly ProductService $products) {}

    /** GET /business/products */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $products = $business->products()
            ->with(['category', 'images', 'promotions'])
            ->orderBy('position')
            ->latest('id')
            ->get();

        return ApiResponse::success(ProductResource::collection($products), 'Products retrieved.');
    }

    /** POST /business/products */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validateData($request);

        $product = $this->products->create($business, $data, $request->file('image'), $request->user());

        return ApiResponse::success(new ProductResource($product), 'Product added.', status: 201);
    }

    /** POST /business/products/{uuid} (with _method=PUT) */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        if ($product === null) {
            return ApiResponse::error('Product not found.', status: 404);
        }

        $data = $this->validateData($request);
        $product = $this->products->update($product, $data, $request->file('image'), $request->user());

        return ApiResponse::success(new ProductResource($product), 'Product updated.');
    }

    /** DELETE /business/products/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        if ($product === null) {
            return ApiResponse::error('Product not found.', status: 404);
        }

        $this->products->delete($product);

        return ApiResponse::success(message: 'Product deleted.');
    }

    /** PATCH /business/products/{uuid}/toggle — quick flip of a single flag. */
    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        if ($product === null) {
            return ApiResponse::error('Product not found.', status: 404);
        }

        $validated = $request->validate([
            'field' => ['required', Rule::in(['is_visible', 'in_stock', 'is_todays_special', 'is_bestseller', 'is_recommended'])],
            'value' => ['required', 'boolean'],
        ]);

        $product->update([$validated['field'] => $validated['value']]);

        return ApiResponse::success(
            new ProductResource($product->load(['category', 'images'])),
            'Product updated.',
        );
    }

    /** PATCH /business/products/reorder */
    public function reorder(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string'],
        ]);

        $this->products->reorder($business, $validated['order']);

        return $this->index($request);
    }

    /** POST /business/products/{uuid}/images */
    public function addImage(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        if ($product === null) {
            return ApiResponse::error('Product not found.', status: 404);
        }

        $request->validate(['image' => ['required', 'image', 'max:4096']]);
        $image = $this->products->addGalleryImage($product, $request->file('image'));

        return ApiResponse::success(
            new ProductResource($product->fresh(['category', 'images'])),
            'Image added.',
            status: 201,
            meta: ['imageId' => $image->uuid],
        );
    }

    /** DELETE /business/products/{uuid}/images/{imageUuid} */
    public function removeImage(Request $request, string $uuid, string $imageUuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        if ($product === null) {
            return ApiResponse::error('Product not found.', status: 404);
        }

        $image = $product->images()->where('uuid', $imageUuid)->first();
        if ($image === null) {
            return ApiResponse::error('Image not found.', status: 404);
        }

        $this->products->removeGalleryImage($image);

        return ApiResponse::success(new ProductResource($product->fresh(['category', 'images'])), 'Image removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_category_id' => ['nullable', 'string'],
            'category_name' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'ingredients' => ['nullable', 'string', 'max:1000'],
            'mrp' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'food_type' => ['nullable', Rule::enum(FoodType::class)],
            'available_from' => ['nullable', 'date_format:H:i'],
            'available_to' => ['nullable', 'date_format:H:i'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'is_todays_special' => ['nullable', 'boolean'],
            'is_bestseller' => ['nullable', 'boolean'],
            'is_recommended' => ['nullable', 'boolean'],
            'in_stock' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    private function find(Request $request, string $uuid): ?Product
    {
        return $this->business($request)->products()->where('uuid', $uuid)->first();
    }
}
