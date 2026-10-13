<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AppIconService;
use App\Services\AuditService;
use App\Services\ImageStorageService;
use App\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform settings (document/phase/14 §Platform Settings / §Maintenance Mode).
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
        private readonly ImageStorageService $storage,
        private readonly AppIconService $icons,
    ) {}

    /**
     * POST /admin/settings/app-icon — use an uploaded image as the app icon
     * (favicon, home screen, PWA). Square, at least 256 px; switches the icon
     * mode to "upload".
     */
    public function uploadAppIcon(Request $request): JsonResponse
    {
        $request->validate([
            'icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096', 'dimensions:min_width=256,min_height=256'],
        ], [
            'icon.dimensions' => 'Use an image at least 256 × 256 pixels (512 × 512 is best).',
        ]);

        $file = $request->file('icon');
        $path = $this->icons->storeUpload($file->getRealPath(), $file->extension() ?: 'png');
        $this->settings->set(['appIconUploadPath' => $path, 'appIconMode' => 'upload']);
        $this->icons->regenerate();
        $this->audit->log($request->user(), 'settings.app_icon', null, 'Uploaded the app icon');

        return ApiResponse::success($this->settings->masked(), 'App icon updated.');
    }

    /** DELETE /admin/settings/app-icon — drop the upload and go back to the theme-drawn icon. */
    public function deleteAppIcon(Request $request): JsonResponse
    {
        $this->icons->deleteUpload();
        $this->settings->set(['appIconUploadPath' => null, 'appIconMode' => 'auto']);
        $this->icons->regenerate();
        $this->audit->log($request->user(), 'settings.app_icon', null, 'Removed the uploaded app icon');

        return ApiResponse::success($this->settings->masked(), 'Using the theme icon again.');
    }

    /** GET /admin/settings */
    public function index(): JsonResponse
    {
        // masked(), never all(): the Razorpay secrets must not reach a browser,
        // where they would sit in devtools, HAR files and any logging proxy.
        return ApiResponse::success($this->settings->masked(), 'Settings retrieved.');
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

            // Razorpay. Secrets may be sent blank, which keeps the stored value.
            'razorpayKeyId' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^rzp_(test|live)_[A-Za-z0-9]+$/'],
            'razorpayKeySecret' => ['sometimes', 'nullable', 'string', 'max:128'],
            'razorpayWebhookSecret' => ['sometimes', 'nullable', 'string', 'max:128'],
            // Capped well below anything a real gateway charges, so a typo such
            // as 25 instead of 2.5 cannot quietly quadruple every online bill.
            'razorpayFeePercent' => ['sometimes', 'numeric', 'min:0', 'max:10'],

            'loginGoogle' => ['sometimes', 'boolean'],
            'loginMobile' => ['sometimes', 'boolean'],

            // Theme
            ...array_fill_keys(SettingService::THEME_COLORS, ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']),
            'themeFont' => ['sometimes', 'string', Rule::in(SettingService::THEME_FONTS)],
            'themeHeadingFont' => ['sometimes', 'string', Rule::in(SettingService::THEME_FONTS)],
            'menuCardColors' => ['sometimes', 'boolean'],
            ...array_fill_keys(SettingService::THEME_EXTRA_COLORS, ['sometimes', 'nullable', 'string', 'regex:/^(#[0-9a-fA-F]{6})?$/']),
            ...array_fill_keys(SettingService::THEME_DARK_COLORS, ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']),
            'themeMode' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'themeRadius' => ['sometimes', Rule::in(['sharp', 'rounded', 'pill'])],
            'themeTextSize' => ['sometimes', Rule::in(['small', 'normal', 'large'])],
            'appIconMode' => ['sometimes', Rule::in(['auto', 'upload'])],

            // Home page layout
            'homeLayout' => ['sometimes', 'nullable', 'array', 'max:'.count(SettingService::HOME_SECTIONS)],
            'homeLayout.*.id' => ['required', 'distinct', Rule::in(SettingService::HOME_SECTIONS)],
            'homeLayout.*.enabled' => ['required', 'boolean'],
            'homeLayout.*.title' => ['nullable', 'string', 'max:40'],
            'homeLayout.*.slot' => ['nullable', Rule::in(SettingService::HOME_SLOTS)],
        ], [
            'razorpayKeyId.regex' => 'That does not look like a Razorpay key id (it starts with rzp_test_ or rzp_live_).',
        ]);

        $this->settings->set($validated);

        // Icons drawn from the theme follow its colours; an uploaded icon is
        // only re-padded (its full-bleed versions sit on the background colour).
        if (array_intersect(array_keys($validated), ['themePrimary', 'themeAccent', 'themeBackground', 'appIconMode']) !== []) {
            $this->icons->regenerate();
        }

        // The audit trail records which settings changed, never their values --
        // that would put the secrets in a table admins can browse.
        $this->audit->log($request->user(), 'settings.update', null, 'Updated platform settings', array_keys($validated));

        return ApiResponse::success($this->settings->masked(), 'Settings updated.');
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
        $this->settings->set([
            'orderSoundPath' => $path,
            'orderSoundUrl' => $this->storage->url($path),
        ]);

        $this->audit->log($request->user(), 'settings.order_sound.upload', null, 'Uploaded order alert sound');

        return ApiResponse::success($this->settings->masked(), 'Order sound updated.');
    }

    /** DELETE /admin/settings/order-sound — revert owners to the built-in ding. */
    public function deleteOrderSound(Request $request): JsonResponse
    {
        $this->storage->delete($this->settings->get('orderSoundPath'));
        $this->settings->set(['orderSoundPath' => null, 'orderSoundUrl' => null]);

        $this->audit->log($request->user(), 'settings.order_sound.delete', null, 'Removed order alert sound');

        return ApiResponse::success($this->settings->masked(), 'Order sound removed.');
    }
}
