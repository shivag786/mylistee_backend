<?php

namespace App\Http\Resources\Admin;

use App\Models\BusinessCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Full category record for the admin management screen (Phase 7.1) — includes
 * moderation/presentation fields the public resource omits.
 *
 * @mixin BusinessCategory
 */
class AdminCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'imageUrl' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'description' => $this->description,
            'altText' => $this->alt_text,
            'position' => (int) $this->sort_order,
            'status' => $this->status,
            'showOnHomepage' => (bool) $this->show_on_homepage,
            'showInSearch' => (bool) $this->show_in_search,
            'businessCount' => $this->when(isset($this->businesses_count), fn () => (int) $this->businesses_count),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
