<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->data['link'] ?? null,
            'isRead' => $this->isRead(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
