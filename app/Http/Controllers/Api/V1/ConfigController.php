<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public app config for the customer PWA — the customer-facing feature toggles
 * the Super Admin controls from the feature-flags panel. Kept to an explicit
 * allowlist so admin-only flags are never exposed.
 */
class ConfigController extends Controller
{
    /** GET /config */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'flags' => [
                'homeCategoryFilter' => FeatureFlag::isEnabled('home_category_filter', true),
            ],
        ], 'App config.');
    }
}
