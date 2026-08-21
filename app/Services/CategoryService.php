<?php

namespace App\Services;

use App\Models\BusinessCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Master category management for the admin (Phase 7.1). Centralises create /
 * update / reorder. The public category list is read uncached, so a change
 * here is visible on the customer side on the very next request.
 */
class CategoryService
{
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

        return $category;
    }

    public function delete(BusinessCategory $category): void
    {
        $this->images->delete($category->image_path);
        $category->delete();
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

    /**
     * Flip a category's homepage / search visibility without touching its other
     * fields (used by the inline admin toggles). Null leaves a flag unchanged.
     */
    public function setVisibility(BusinessCategory $category, ?bool $homepage, ?bool $search): BusinessCategory
    {
        if ($homepage !== null) {
            $category->show_on_homepage = $homepage;
        }
        if ($search !== null) {
            $category->show_in_search = $search;
        }

        $category->save();

        return $category;
    }

}
