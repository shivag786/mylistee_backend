<?php

namespace App\Http\Resources;

use App\Models\BusinessTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessTable
 */
class BusinessTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'capacity' => $this->capacity,
            'sortOrder' => $this->sort_order,
            'scanCount' => $this->scan_count,
            'status' => $this->status,
            // The scan URL a customer opens; QR image is rendered client-side.
            'qrUrl' => $this->qrUrl(),
        ];
    }

    /** {frontend}/b/{slug}?table={uuid} — mirrors QrService::profileUrl(). */
    private function qrUrl(): ?string
    {
        $slug = $this->business?->slug;
        if ($slug === null) {
            return null;
        }
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return "{$base}/b/{$slug}?table={$this->uuid}";
    }
}
