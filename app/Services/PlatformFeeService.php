<?php

namespace App\Services;

/**
 * What comes out of a shop's order, borne by the shop — the customer pays the
 * same price online or in cash. Shops pay Listee by subscription, so there is
 * no per-order platform fee; only the payment gateway's fee (admin-set,
 * default 2%) on the part paid online. Cash orders cost the shop nothing.
 * Recorded on each order, so it can be settled.
 */
class PlatformFeeService
{
    public function __construct(private readonly SettingService $settings) {}

    /** Always 0 — the subscription replaces a cut of each order. */
    public function platformPercent(): float
    {
        return 0.0;
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
