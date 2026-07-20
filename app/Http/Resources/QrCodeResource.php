<?php

namespace App\Http\Resources;

use App\Models\QrCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrCode
 */
class QrCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'url' => $this->url,
            'downloadCount' => $this->download_count,
            'scanCount' => $this->scan_count,
        ];
    }
}
