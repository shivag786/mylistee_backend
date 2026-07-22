<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

/**
 * Per-business menu sections (Phase 7.2). Sections can be managed explicitly or
 * created on the fly when an owner types a new one on the product form.
 */
class ProductCategoryService
{
    public function create(Business $business, string $name): ProductCategory
    {
        return $business->productCategories()->create([
            'name' => trim($name),
            'position' => (int) $business->productCategories()->max('position') + 1,
        ]);
    }

    /**
     * Resolve a section for a product: prefer an explicit uuid, else find-or-create
     * one by name. Returns null when neither is provided.
     */
    public function resolve(Business $business, ?string $uuid, ?string $name): ?ProductCategory
    {
        if ($uuid) {
            return $business->productCategories()->where('uuid', $uuid)->first();
        }

        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return $business->productCategories()->firstOrCreate(
            ['name' => $name],
            ['position' => (int) $business->productCategories()->max('position') + 1],
        );
    }

    public function update(ProductCategory $category, string $name): ProductCategory
    {
        $category->update(['name' => trim($name)]);

        return $category;
    }

    /** Delete a section; its products keep working (category_id → null). */
    public function delete(ProductCategory $category): void
    {
        $category->delete();
    }

    /**
     * @param  array<int, string>  $orderedUuids
     */
    public function reorder(Business $business, array $orderedUuids): void
    {
        DB::transaction(function () use ($business, $orderedUuids): void {
            foreach ($orderedUuids as $position => $uuid) {
                $business->productCategories()->where('uuid', $uuid)->update(['position' => $position + 1]);
            }
        });
    }
}
