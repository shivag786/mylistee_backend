<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AppIconService;
use App\Services\AuditService;
use App\Services\GmailMailer;
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
            'platformFeePercent' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'customerFeePercent' => ['sometimes', 'numeric', 'min:0', 'max:10'],

            // Personal link packs + expiry reminders
            'personalLinkTrialDays' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'personalLinkPrice30' => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'personalLinkPrice90' => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'personalLinkPrice365' => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'personalLinkReminderEnabled' => ['sometimes', 'boolean'],
            'personalLinkReminderDays' => ['sometimes', 'integer', 'min:1', 'max:15'],
            'personalLinkReminderTitle' => ['sometimes', 'string', 'max:120'],
            'personalLinkReminderBody' => ['sometimes', 'string', 'max:500'],
            'personalLinkReminderEmail' => ['sometimes', 'boolean'],
            'gmailAddress' => ['sometimes', 'nullable', 'email', 'max:120'],
            'gmailAppPassword' => ['sometimes', 'nullable', 'string', 'max:64'],

            'loginGoogle' => ['sometimes', 'boolean'],
            'loginMobile' => ['sometimes', 'boolean'],
            'spinShowCounts' => ['sometimes', 'boolean'],
            'customerSoundsEnabled' => ['sometimes', 'boolean'],
            'aiEnabled' => ['sometimes', 'boolean'],
            'openaiApiKey' => ['sometimes', 'nullable', 'string', 'max:300'],
            'openaiModel' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'aiMonthlyLimit' => ['sometimes', 'integer', 'min:0', 'max:100000'],

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

            // Which page `/` shows signed-out visitors, and the landing page's content
            'homePage' => ['sometimes', Rule::in(['shops', 'landing'])],
            'landingPage' => ['sometimes', 'nullable', 'array'],
            'landingPage.sections' => ['sometimes', 'array', 'max:'.count(SettingService::LANDING_SECTIONS)],
            'landingPage.sections.*.id' => ['required', 'distinct', Rule::in(SettingService::LANDING_SECTIONS)],
            'landingPage.sections.*.enabled' => ['required', 'boolean'],
            'landingPage.sections.*.title' => ['nullable', 'string', 'max:80'],
            'landingPage.sections.*.subtitle' => ['nullable', 'string', 'max:240'],
            'landingPage.hero' => ['sometimes', 'array'],
            'landingPage.hero.badge' => ['nullable', 'string', 'max:60'],
            'landingPage.hero.title' => ['nullable', 'string', 'max:120'],
            'landingPage.hero.subtitle' => ['nullable', 'string', 'max:300'],
            'landingPage.hero.customerCta' => ['nullable', 'string', 'max:40'],
            'landingPage.hero.vendorCta' => ['nullable', 'string', 'max:40'],
            'landingPage.customerBenefits' => ['sometimes', 'array', 'max:12'],
            'landingPage.customerBenefits.*.title' => ['required', 'string', 'max:60'],
            'landingPage.customerBenefits.*.text' => ['nullable', 'string', 'max:240'],
            'landingPage.vendorBenefits' => ['sometimes', 'array', 'max:12'],
            'landingPage.vendorBenefits.*.title' => ['required', 'string', 'max:60'],
            'landingPage.vendorBenefits.*.text' => ['nullable', 'string', 'max:240'],
            'landingPage.faqs' => ['sometimes', 'array', 'max:30'],
            'landingPage.faqs.*.q' => ['required', 'string', 'max:200'],
            'landingPage.faqs.*.a' => ['required', 'string', 'max:1500'],

            // SEO. Verification codes and the Analytics id end up in the page
            // head, so they are held to the characters those formats use.
            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.siteTitle' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:320'],
            'seo.keywords' => ['nullable', 'string', 'max:400'],
            'seo.ogImageUrl' => ['nullable', 'url', 'max:500'],
            'seo.googleVerification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'seo.bingVerification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'seo.googleAnalyticsId' => ['nullable', 'string', 'max:20', 'regex:/^G-[A-Z0-9]+$/'],
            'seo.noindex' => ['sometimes', 'boolean'],
            'seo.pages' => ['sometimes', 'array'],
            'seo.pages.*.title' => ['nullable', 'string', 'max:70'],
            'seo.pages.*.description' => ['nullable', 'string', 'max:320'],
        ], [
            'seo.googleVerification.regex' => 'Paste only the code from the content="…" part of Google\'s meta tag.',
            'seo.bingVerification.regex' => 'Paste only the code from the content="…" part of Bing\'s meta tag.',
            'seo.googleAnalyticsId.regex' => 'A Google Analytics 4 id looks like G-XXXXXXXXXX.',
            'razorpayKeyId.regex' => 'That does not look like a Razorpay key id (it starts with rzp_test_ or rzp_live_).',
        ]);

        // Per-page SEO is kept to the pages the site knows; anything else is dropped.
        if (isset($validated['seo']['pages']) && is_array($validated['seo']['pages'])) {
            $validated['seo']['pages'] = array_intersect_key($validated['seo']['pages'], array_flip(SettingService::SEO_PAGES));
        }

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

    /** POST /admin/settings/test-email — send a test mail through the saved Gmail account. */
    public function testEmail(Request $request, GmailMailer $mailer): JsonResponse
    {
        $validated = $request->validate(['to' => ['required', 'email', 'max:120']]);

        if (! $mailer->isConfigured()) {
            return ApiResponse::error('Save a Gmail address and App Password first.', status: 422);
        }

        $ok = $mailer->send(
            $validated['to'],
            'Test email from '.$this->settings->get('brandName'),
            'Gmail is connected. Personal link reminders can now be emailed to shop owners.',
        );

        return $ok
            ? ApiResponse::success(null, 'Test email sent. Check the inbox (and spam).')
            : ApiResponse::error('Gmail refused the login. Check the address and use an App Password (Google Account → Security → App passwords).', status: 422);
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

    /**
     * POST /admin/settings/customer-sound/{kind} -- the tone customers hear when
     * they place an order (`placed`) or the shop accepts it (`accepted`).
     */
    public function uploadCustomerSound(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['placed', 'accepted'], true), 404);
        $request->validate([
            'sound' => [
                'required', 'file',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,audio/webm,audio/mp4,audio/aac,audio/x-m4a',
                'max:1024',
            ],
        ]);
        $key = 'customerSound'.ucfirst($kind);
        $this->storage->delete($this->settings->get($key.'Path'));
        $path = $this->storage->store($request->file('sound'), 'sounds');
        $this->settings->set([$key.'Path' => $path, $key.'Url' => $this->storage->url($path)]);

        $this->audit->log($request->user(), "settings.customer_sound.{$kind}.upload", null, "Uploaded customer {$kind} sound");

        return ApiResponse::success($this->settings->masked(), 'Sound updated.');
    }

    /** DELETE /admin/settings/customer-sound/{kind} -- back to the built-in tone. */
    public function deleteCustomerSound(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['placed', 'accepted'], true), 404);
        $key = 'customerSound'.ucfirst($kind);
        $this->storage->delete($this->settings->get($key.'Path'));
        $this->settings->set([$key.'Path' => null, $key.'Url' => null]);

        $this->audit->log($request->user(), "settings.customer_sound.{$kind}.delete", null, "Removed customer {$kind} sound");

        return ApiResponse::success($this->settings->masked(), 'Sound removed.');
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
