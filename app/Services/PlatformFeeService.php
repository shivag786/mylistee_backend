<?php

namespace App\Services;

/**
 * Listee's cut of an order, borne by the shop — the customer pays the same
 * price online or in cash. Two parts, both admin-set:
 *  - platform fee: on every order (default 2%);
 *  - payment fee: what the gateway charges, on the part paid online (default 2%).
 * The shop keeps the rest. Recorded on each order, so it can be settled.
 */
class PlatformFeeService
{
    public function __construct(private readonly SettingService $settings) {}

    public function platformPercent(): float
    {
        return max(0.0, (float) $this->settings->get('platformFeePercent', 2.0));
    }

    public function gatewayPercent(): float
    {
        return max(0.0, (float) $this->settings->get('razorpayFeePercent', 2.0));
    }

    /**
     * @return array{platform: float, gateway: float, vendor: float}
     */
    public function split(float $total, float $onlineAmount): array
    {
        $platform = round($total * $this->platformPercent() / 100, 2);
        $gateway = round($onlineAmount * $this->gatewayPercent() / 100, 2);

        return [
            'platform' => $platform,
            'gateway' => $gateway,
            'vendor' => round(max(0.0, $total - $platform - $gateway), 2),
        ];
    }
}
