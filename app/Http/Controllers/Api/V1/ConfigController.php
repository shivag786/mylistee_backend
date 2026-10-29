<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Services\RazorpayService;
use App\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public app config for the customer PWA — the customer-facing feature toggles
 * the Super Admin controls from the feature-flags panel. Kept to an explicit
 * allowlist so admin-only flags are never exposed.
 */
class ConfigController extends Controller
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly RazorpayService $razorpay,
    ) {}

    /** GET /config */
    public function index(): JsonResponse
    {
        // Which owner-menu modules are enabled (admin-controlled). Default ON so a
        // missing flag never hides a module. Keys mirror config/owner_modules.php.
        $ownerModules = [];
        foreach (array_keys((array) config('owner_modules', [])) as $id) {
            $ownerModules[$id] = FeatureFlag::isEnabled("owner_{$id}", true);
        }

        return ApiResponse::success([
            'flags' => [
                'homeCategoryFilter' => FeatureFlag::isEnabled('home_category_filter', true),
                // Drives the install banner AND the service worker. Off means the
                // client unregisters the worker and drops the manifest, so the
                // app stops behaving as an installable PWA everywhere at once.
                'pwa' => FeatureFlag::isEnabled('pwa', true),
            ],
            // Admin-configurable new-order alert sound for owners (null = built-in ding).
            'orderSoundUrl' => $this->settings->get('orderSoundUrl'),
            'ownerModules' => $ownerModules,
            // Shop rewards (bill discount, come-back, loyalty steps, coupon codes).
            'rewardsEnabled' => FeatureFlag::isEnabled('owner_rewards', true),
            // Admin-set colours and fonts, applied app-wide on every device, plus
            // whether shop menu cards are tinted with each dish's colours.
            'theme' => $this->settings->theme(),
            // The Listee Business phone app's own look (admin → Settings → Business app).
            'vendorApp' => app(\App\Services\VendorAppThemeService::class)->get(),
            // Admin-arranged home page sections; null = the built-in order.
            'homeLayout' => $this->settings->get('homeLayout'),
            // Whether a live payment gateway is wired up. Only a boolean — no key
            // is exposed here; the publishable key comes back from the checkout
            // call itself. The owner UI uses this to decide between the paid
            // checkout flow and the pre-gateway simulated upgrade (local/demo).
            'payments' => [
                'razorpay' => $this->razorpay->isConfigured(),
                // Fee added to the customer's online share, in percent (0 by default).
                'convenienceFeePercent' => $this->razorpay->isConfigured() ? (float) $this->settings->get('customerFeePercent', 0) : 0,
                // Listee's cut of a shop's order: platform on every order, the
                // gateway's on the online part. Shown to shops per product.
                'platformFeePercent' => (float) $this->settings->get('platformFeePercent', 2.0),
                'gatewayFeePercent' => $this->razorpay->feePercent(),
            ],
            // Which ways in the customer login page offers. SettingService keeps
            // at least one on, so the page is never left with nothing.
            'auth' => [
                'google' => (bool) $this->settings->get('loginGoogle', true),
                'mobile' => (bool) $this->settings->get('loginMobile', false),
                // "Forgot PIN?" by a WhatsApp code — only when an OTP template is set.
                'whatsappOtp' => app(\App\Services\WhatsApp\WhatsAppOtpService::class)->available(),
            ],
            // So the home page can say what coins are worth in rupees.
            'coins' => [
                // Customers see coins only while the admin's "Loyalty coins" switch is on.
                'enabled' => app(\App\Services\LoyaltyService::class)->isEnabled(),
                'value' => (int) config('loyalty.coin_value', 1),
                'welcome' => (int) config('loyalty.earn.welcome', 0),
            ],
        ], 'App config.');
    }
}
