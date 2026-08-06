<?php

namespace App\Http\Resources;

use App\Enums\ServiceType;
use App\Models\BusinessServiceSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessServiceSetting
 */
class BusinessServiceSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $modes = array_map(fn (ServiceType $t) => $t->value, $this->enabledTypes());

        return [
            'modes' => $modes,
            'defaultMode' => in_array($this->default_mode, $modes, true) ? $this->default_mode : $modes[0],
            'deliveryFee' => (float) $this->delivery_fee,
        ];
    }
}
