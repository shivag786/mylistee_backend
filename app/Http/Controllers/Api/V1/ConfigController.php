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
            // Whether a live payment gateway is wired up. Only a boolean — no key
            // is exposed here; the publishable key comes back from the checkout
            // call itself. The owner UI uses this to decide between the paid
            // checkout flow and the pre-gateway simulated upgrade (local/demo).
            'payments' => ['razorpay' => $this->razorpay->isConfigured()],
        ], 'App config.');
    }
}
