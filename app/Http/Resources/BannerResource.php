<?php

namespace App\Http\Resources;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Banner
 * Public banner shape for the homepage carousel.
 */
class BannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'imageUrl' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'linkUrl' => $this->link_url,
            'placement' => $this->placement instanceof BannerPlacement ? $this->placement->value : $this->placement,
        ];
    }
}
