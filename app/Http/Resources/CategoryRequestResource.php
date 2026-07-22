<?php

namespace App\Http\Resources;

use App\Enums\CategoryRequestStatus;
use App\Models\CategoryRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A category request — shown to the requesting owner and to the admin queue
 * (Phase 7.1).
 *
 * @mixin CategoryRequest
 */
class CategoryRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof CategoryRequestStatus ? $this->status->value : $this->status;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'sampleImageUrl' => $this->sample_image_path
                ? Storage::disk('public')->url($this->sample_image_path)
                : null,
            'status' => $status,
            'reviewNote' => $this->review_note,
            'requestedBy' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'businessName' => $this->whenLoaded('business', fn () => $this->business?->name),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
