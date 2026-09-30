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
            'paymentMode' => $this->payment_mode ?? 'full',
            'partialPercent' => (int) ($this->partial_percent ?? 50),
            'codEnabled' => (bool) ($this->cod_enabled ?? true),
        ];
    }
}
