<?php

namespace App\Services;

use App\Enums\ServiceType;
use App\Models\Business;
use App\Models\BusinessServiceSetting;

/**
 * Owns a business's service configuration (which fulfilment modes + delivery fee).
 * Unconfigured businesses resolve to pickup-only, keeping existing shops untouched.
 */
class ServiceSettingService
{
    /** The setting row, created with pickup-only defaults on first access. */
    public function for(Business $business): BusinessServiceSetting
    {
        return $business->serviceSetting()->firstOrCreate(
            [],
            [
                'modes' => [ServiceType::default()->value],
                'default_mode' => ServiceType::default()->value,
                'delivery_fee' => 0,
            ],
        );
    }

    /**
     * Update the enabled modes, default mode, and delivery fee. Pickup is always
     * kept enabled as a floor, and the default mode is coerced into the set.
     *
     * @param  array<int, string>  $modes
     */
    public function update(Business $business, array $modes, string $defaultMode, float $deliveryFee): BusinessServiceSetting
    {
        // Normalise: valid ServiceType values only, unique, pickup guaranteed.
        $valid = array_values(array_unique(array_filter(
            $modes,
            fn ($m) => ServiceType::tryFrom((string) $m) !== null,
        )));
        if (! in_array(ServiceType::Pickup->value, $valid, true)) {
            $valid[] = ServiceType::Pickup->value;
        }

        if (! in_array($defaultMode, $valid, true)) {
            $defaultMode = $valid[0];
        }

        $setting = $this->for($business);
        $setting->update([
            'modes' => $valid,
            'default_mode' => $defaultMode,
            'delivery_fee' => max(0, $deliveryFee),
        ]);

        return $setting->fresh();
    }
}
