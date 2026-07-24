<?php

namespace App\Http\Resources\Admin;

use App\Models\BusinessImportLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessImportLog
 */
class BusinessImportLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'source' => $this->source,
            'sourceUrl' => $this->source_url,
            'placeId' => $this->place_id,
            'status' => $this->status,
            'updatedFields' => $this->updated_fields ?? [],
            'message' => $this->message,
            'importedBy' => $this->importer?->name ?? 'System',
            'businessId' => $this->business?->uuid,
            'businessName' => $this->business?->name,
            'ipAddress' => $this->ip_address,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
