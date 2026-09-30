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
                'payment_mode' => BusinessServiceSetting::PAYMENT_FULL,
                'partial_percent' => 50,
                'cod_enabled' => true,
            ],
        );
    }

    /**
     * Update the enabled modes, default mode, and delivery fee. Pickup is always
     * kept enabled as a floor, and the default mode is coerced into the set.
     *
     * @param  array<int, string>  $modes
     */
    public function update(
        Business $business,
        array $modes,
        string $defaultMode,
        float $deliveryFee,
        ?string $paymentMode = null,
        ?int $partialPercent = null,
        ?bool $codEnabled = null,
    ): BusinessServiceSetting {
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

        // Payment fields are optional so an older client that only knows about
        // service modes can still save them without resetting payment choices.
        $payment = array_filter([
            'payment_mode' => $paymentMode,
            'partial_percent' => $partialPercent === null
                ? null
                : max(BusinessServiceSetting::PARTIAL_MIN, min(BusinessServiceSetting::PARTIAL_MAX, $partialPercent)),
            'cod_enabled' => $codEnabled,
        ], fn ($v) => $v !== null);

        $setting->update([
            'modes' => $valid,
            'default_mode' => $defaultMode,
            'delivery_fee' => max(0, $deliveryFee),
            ...$payment,
        ]);

        return $setting->fresh();
    }
}
