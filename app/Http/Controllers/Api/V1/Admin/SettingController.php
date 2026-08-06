<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\ImageStorageService;
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
        private readonly ImageStorageService $storage,
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

    /**
     * POST /admin/settings/order-sound — upload the new-order alert sound owners
     * hear. Replaces any existing one; applies platform-wide via GET /config.
     */
    public function uploadOrderSound(Request $request): JsonResponse
    {
        $request->validate([
            'sound' => [
                'required', 'file',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,audio/webm,audio/mp4,audio/aac,audio/x-m4a',
                'max:1024', // KB (1 MB) — an alert tone is tiny
            ],
        ]);

        // Remove the previous file so uploads don't pile up on disk.
        $this->storage->delete($this->settings->get('orderSoundPath'));

        $path = $this->storage->store($request->file('sound'), 'sounds');
        $all = $this->settings->set([
            'orderSoundPath' => $path,
            'orderSoundUrl' => $this->storage->url($path),
        ]);

        $this->audit->log($request->user(), 'settings.order_sound.upload', null, 'Uploaded order alert sound');

        return ApiResponse::success($all, 'Order sound updated.');
    }

    /** DELETE /admin/settings/order-sound — revert owners to the built-in ding. */
    public function deleteOrderSound(Request $request): JsonResponse
    {
        $this->storage->delete($this->settings->get('orderSoundPath'));
        $all = $this->settings->set(['orderSoundPath' => null, 'orderSoundUrl' => null]);

        $this->audit->log($request->user(), 'settings.order_sound.delete', null, 'Removed order alert sound');

        return ApiResponse::success($all, 'Order sound removed.');
    }
}
