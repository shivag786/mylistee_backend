<?php

namespace App\Http\Resources;

use App\Models\BusinessGallery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin BusinessGallery
 */
class GalleryImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'sortOrder' => $this->sort_order,
        ];
    }
}
