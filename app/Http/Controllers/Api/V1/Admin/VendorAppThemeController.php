<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\VendorAppThemeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin → Settings → Business app: the vendor phone app's colours and splash time. */
class VendorAppThemeController extends Controller
{
    public function __construct(
        private readonly VendorAppThemeService $theme,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/vendor-app-theme */
    public function show(): JsonResponse
    {
        return ApiResponse::success([
            'theme' => $this->theme->get(),
            'defaults' => VendorAppThemeService::DEFAULTS,
        ], 'Business app theme.');
    }

    /** PUT /admin/vendor-app-theme */
    public function update(Request $request): JsonResponse
    {
        $rules = ['splashSeconds' => ['sometimes', 'integer', 'min:1', 'max:6']];
        foreach (VendorAppThemeService::COLORS as $key) {
            $rules[$key] = ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        }
        $data = $request->validate($rules, ['regex' => 'Use a colour like #FAC83A.']);

        $theme = $this->theme->update($data);
        $this->audit->log($request->user(), 'settings.vendor_app_theme', null, 'Updated the business app theme');

        return ApiResponse::success(['theme' => $theme, 'defaults' => VendorAppThemeService::DEFAULTS], 'Business app theme saved.');
    }

    /** DELETE /admin/vendor-app-theme — back to the Listee Business brand. */
    public function reset(Request $request): JsonResponse
    {
        $theme = $this->theme->reset();
        $this->audit->log($request->user(), 'settings.vendor_app_theme', null, 'Reset the business app theme');

        return ApiResponse::success(['theme' => $theme, 'defaults' => VendorAppThemeService::DEFAULTS], 'Business app theme reset.');
    }
}
