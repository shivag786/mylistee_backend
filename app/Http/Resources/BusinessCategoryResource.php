<?php

namespace App\Http\Resources;

use App\Models\BusinessCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin BusinessCategory
 */
class BusinessCategoryResource extends JsonResource
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
            'showOnHomepage' => (bool) $this->show_on_homepage,
            'showInSearch' => (bool) $this->show_in_search,
            'businessType' => ($this->business_type ?? \App\Enums\BusinessType::Food)->value,
        ];
    }
}
