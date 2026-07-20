<?php

namespace App\Http\Resources\Admin;

use App\Models\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeatureFlag
 */
class FeatureFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'enabled' => (bool) $this->enabled,
        ];
    }
}
