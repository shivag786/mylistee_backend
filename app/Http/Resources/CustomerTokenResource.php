<?php

namespace App\Http\Resources;

use App\Models\CustomerToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin CustomerToken
 */
class CustomerTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'expiresInSeconds' => max(0, Carbon::now()->diffInSeconds($this->expires_at, false)),
        ];
    }
}
