<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Product catalogue management for the owner (Phase 7.2). Handles the menu
 * section resolution, image storage, and display ordering so controllers stay
 * thin (document/phase/04 §Service Layer).
 */
class ProductService
{
    public function __construct(
        private readonly ImageStorageService $images,
        private readonly ProductCategoryService $categories,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Business $business, array $data, ?UploadedFile $image, User $actor): Product
    {
        $category = $this->categories->resolve(
            $business,
            $data['product_category_id'] ?? null,
            $data['category_name'] ?? null,
        );

        $product = new Product($this->attributes($data));
        $product->business_id = $business->id;
        $product->product_category_id = $category?->id;
        $product->created_by = $actor->id;
        $product->position = (int) $business->products()->max('position') + 1;

        if ($image !== null) {
            $product->image_path = $this->images->store($image, 'products');
        }

        $product->save();

        return $product->load('category', 'images');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, ?UploadedFile $image, User $actor): Product
    {
        if (array_key_exists('product_category_id', $data) || array_key_exists('category_name', $data)) {
            $category = $this->categories->resolve(
                $product->business,
                $data['product_category_id'] ?? null,
                $data['category_name'] ?? null,
            );
            $product->product_category_id = $category?->id;
        }

        $product->fill($this->attributes($data));
        $product->updated_by = $actor->id;

        if ($image !== null) {
            $this->images->delete($product->image_path);
            $product->image_path = $this->images->store($image, 'products');
        }

        $product->save();

        return $product->load('category', 'images');
    }

    public function delete(Product $product): void
    {
        foreach ($product->images as $image) {
            $this->images->delete($image->image_path);
        }
        $this->images->delete($product->image_path);
        $product->delete();
    }

    /**
     * @param  array<int, string>  $orderedUuids
     */
    public function reorder(Business $business, array $orderedUuids): void
    {
        DB::transaction(function () use ($business, $orderedUuids): void {
            foreach ($orderedUuids as $position => $uuid) {
                $business->products()->where('uuid', $uuid)->update(['position' => $position + 1]);
            }
        });
    }

    public function addGalleryImage(Product $product, UploadedFile $image): ProductImage
    {
        return $product->images()->create([
            'image_path' => $this->images->store($image, 'products'),
            'position' => (int) $product->images()->max('position') + 1,
        ]);
    }

    public function removeGalleryImage(ProductImage $image): void
    {
        $this->images->delete($image->image_path);
        $image->delete();
    }

    /**
     * Whitelist and normalise the writable product attributes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $fields = [
            'name', 'description', 'ingredients', 'mrp', 'selling_price', 'food_type',
            'available_from', 'available_to', 'prep_minutes',
        ];
        $flags = [
            'is_todays_special', 'is_bestseller', 'is_recommended', 'in_stock', 'is_visible',
        ];

        $attributes = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        foreach ($flags as $flag) {
            if (array_key_exists($flag, $data)) {
                $attributes[$flag] = (bool) $data[$flag];
            }
        }

        return $attributes;
    }
}
