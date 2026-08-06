<?php

namespace App\Http\Resources\Admin;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Banner
 * Admin banner shape — includes schedule, active state and live status.
 */
class AdminBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $placement = $this->placement instanceof BannerPlacement ? $this->placement : BannerPlacement::from($this->placement);
        $now = now();
        $live = $this->is_active
            && ($this->starts_at === null || $this->starts_at <= $now)
            && ($this->ends_at === null || $this->ends_at >= $now);

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'imageUrl' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'linkUrl' => $this->link_url,
            'placement' => $placement->value,
            'placementLabel' => $placement->label(),
            'position' => (int) $this->position,
            // Naive wall-clock (matches <input type="datetime-local">) — no timezone
            // conversion, so the time the admin typed round-trips unchanged.
            'startsAt' => $this->starts_at?->format('Y-m-d\TH:i'),
            'endsAt' => $this->ends_at?->format('Y-m-d\TH:i'),
            'isActive' => (bool) $this->is_active,
            'isLive' => $live,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
