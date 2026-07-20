<?php

namespace App\Http\Resources\Admin;

use App\Models\CmsPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CmsPage
 */
class CmsPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
