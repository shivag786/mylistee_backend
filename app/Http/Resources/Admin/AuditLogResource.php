<?php

namespace App\Http\Resources\Admin;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'action' => $this->action,
            'description' => $this->description,
            'actorName' => $this->user?->name ?? 'System',
            'subjectType' => $this->subject_type ? class_basename($this->subject_type) : null,
            'subjectId' => $this->subject_id,
            'meta' => $this->meta,
            'ipAddress' => $this->ip_address,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
