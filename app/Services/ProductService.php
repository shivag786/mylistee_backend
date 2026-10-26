<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        // The plan's menu size (null = unlimited).
        $limit = $business->currentPlan()?->max_products;
        if ($limit !== null && $business->products()->count() >= $limit) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => ["Your plan allows {$limit} products. Upgrade to add more."],
            ]);
        }

        $category = $this->categories->resolve(
            $business,
            $data['product_category_id'] ?? null,
            $data['category_name'] ?? null,
        );

        $product = new Product($this->attributes($data));
        $product->options = $this->options($data, null);
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
        $product->options = $this->options($data, $product->options);
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
            'available_from', 'available_to', 'prep_minutes', 'stock_qty',
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

        // A count decides stock: zero is sold out, a fresh count is back on sale.
        if (array_key_exists('stock_qty', $data) && $data['stock_qty'] !== null && $data['stock_qty'] !== '') {
            $attributes['stock_qty'] = (int) $data['stock_qty'];
            $attributes['in_stock'] = (int) $data['stock_qty'] > 0;
        }

        return $attributes;
    }

    /**
     * Merge sizes / add-ons / discount from a request into the stored options.
     * A key left out of the request keeps its stored value; an empty list or a
     * null discount clears it. Each size and add-on keeps a short stable id, so
     * a cart that picked "Large" still matches after the owner renames it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>|null
     */
    private function options(array $data, ?array $current): ?array
    {
        $options = $current ?? [];

        foreach (['variants', 'addons'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $options[$key] = array_values(array_map(fn (array $row): array => array_filter([
                'id' => filled($row['id'] ?? null) ? (string) $row['id'] : Str::lower(Str::random(8)),
                // Sizes may also carry a type ("Steam" / "Fried") and a quantity ("6 pcs").
                'type' => $key === 'variants' && filled($row['type'] ?? null) ? trim((string) $row['type']) : null,
                'name' => trim((string) ($row['name'] ?? '')),
                'qty' => $key === 'variants' && filled($row['qty'] ?? null) ? trim((string) $row['qty']) : null,
                'price' => round((float) $row['price'], 2),
            ], fn ($v) => $v !== null), $data[$key] ?? []));
        }

        if (array_key_exists('discount', $data)) {
            $discount = $data['discount'];
            $options['discount'] = is_array($discount) && (float) ($discount['value'] ?? 0) > 0
                ? ['type' => $discount['type'], 'value' => round((float) $discount['value'], 2)]
                : null;
        }

        $options = array_filter($options, fn ($v) => $v !== null && $v !== []);

        return $options === [] ? null : $options;
    }
}
