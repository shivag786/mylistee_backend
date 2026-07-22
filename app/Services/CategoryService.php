<?php

namespace App\Services;

use App\Models\BusinessCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Master category management for the admin (Phase 7.1). Centralises create /
 * update / reorder and keeps the public `categories.active` cache (Milestone 15)
 * in sync so changes appear immediately on the customer side.
 */
class CategoryService
{
    /** Cache key used by the public CategoryController. */
    private const CACHE_KEY = 'categories.active';

    public function __construct(private readonly ImageStorageService $images) {}

    /**
     * Create a category. `slug` and `alt_text` fall back to the name when blank
     * so the admin never has to fill boilerplate (07B — less typing).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $image = null): BusinessCategory
    {
        $category = new BusinessCategory($this->attributes($data));

        if (empty($category->sort_order)) {
            $category->sort_order = (int) BusinessCategory::max('sort_order') + 1;
        }
        if ($image !== null) {
            $category->image_path = $this->images->store($image, 'categories');
        }

        $category->save();
        $this->bustCache();

        return $category;
    }

    /**
     * Update a category; replacing the image cleans up the old file.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(BusinessCategory $category, array $data, ?UploadedFile $image = null): BusinessCategory
    {
        $category->fill($this->attributes($data, $category));

        if ($image !== null) {
            $this->images->delete($category->image_path);
            $category->image_path = $this->images->store($image, 'categories');
        }

        $category->save();
        $this->bustCache();

        return $category;
    }

    public function delete(BusinessCategory $category): void
    {
        $this->images->delete($category->image_path);
        $category->delete();
        $this->bustCache();
    }

    /**
     * Persist a new display order. `$orderedUuids` is the full list of category
     * UUIDs in the desired order; position is the array index.
     *
     * @param  array<int, string>  $orderedUuids
     */
    public function reorder(array $orderedUuids): void
    {
        DB::transaction(function () use ($orderedUuids): void {
            foreach ($orderedUuids as $position => $uuid) {
                BusinessCategory::where('uuid', $uuid)->update(['sort_order' => $position + 1]);
            }
        });

        $this->bustCache();
    }

    /**
     * Map validated input to model attributes, applying the name-based defaults
     * for slug and alt text only when those fields are left blank.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?BusinessCategory $existing = null): array
    {
        $attributes = array_filter(
            [
                'name' => $data['name'] ?? null,
                'icon' => $data['icon'] ?? null,
                'description' => $data['description'] ?? null,
                'alt_text' => $data['alt_text'] ?? null,
                'slug' => $data['slug'] ?? null,
                'sort_order' => $data['sort_order'] ?? null,
                'status' => $data['status'] ?? null,
            ],
            fn ($value) => $value !== null,
        );

        // Booleans are always present when sent, but may be `false` — handle explicitly.
        foreach (['show_on_homepage', 'show_in_search'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $attributes[$flag] = (bool) $data[$flag];
            }
        }

        $name = $attributes['name'] ?? $existing?->name;

        if (empty($attributes['slug']) && $existing === null && $name) {
            $attributes['slug'] = Str::slug($name);
        }
        if (empty($attributes['alt_text']) && $name) {
            $attributes['alt_text'] = $name;
        }

        return $attributes;
    }

    private function bustCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
