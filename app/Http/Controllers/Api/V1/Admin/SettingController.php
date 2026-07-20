<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform settings (document/phase/14 §Platform Settings / §Maintenance Mode).
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/settings */
    public function index(): JsonResponse
    {
        return ApiResponse::success($this->settings->all(), 'Settings retrieved.');
    }

    /** PUT /admin/settings */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brandName' => ['sometimes', 'string', 'max:60'],
            'supportEmail' => ['sometimes', 'nullable', 'email', 'max:120'],
            'supportPhone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'defaultLanguage' => ['sometimes', 'string', 'max:8'],
            'maintenanceMode' => ['sometimes', 'boolean'],
            'maintenanceMessage' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $all = $this->settings->set($validated);
        $this->audit->log($request->user(), 'settings.update', null, 'Updated platform settings', array_keys($validated));

        return ApiResponse::success($all, 'Settings updated.');
    }
}
