<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
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
    public function __construct(private readonly SettingService $settings) {}

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
            ],
            // Admin-configurable new-order alert sound for owners (null = built-in ding).
            'orderSoundUrl' => $this->settings->get('orderSoundUrl'),
            'ownerModules' => $ownerModules,
        ], 'App config.');
    }
}
