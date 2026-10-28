<?php

namespace App\Services\WhatsApp;

use App\Models\Business;
use App\Models\ShopCustomer;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends one approved template to one number and logs it. Never throws: a
 * failed WhatsApp must not break an order or a login — the failure is kept on
 * the message row for the admin's Messages list.
 */
class WhatsAppSender
{
    public function __construct(
        private readonly InteraktClient $client,
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppTemplateService $templates,
    ) {}

    /**
     * @param  array<string, string|null>  $context  see WhatsAppTemplateService::SOURCES
     * @param  array<string, string>  $typed  owner-typed values
     */
    public function send(
        WhatsAppTemplate $template,
        string $phone,
        string $purpose,
        array $context = [],
        array $typed = [],
        ?int $businessId = null,
        ?int $campaignId = null,
    ): WhatsAppMessage {
        $digits = ShopCustomer::normalizePhone($phone) ?? preg_replace('/\D+/', '', $phone);

        $message = WhatsAppMessage::create([
            'template_id' => $template->id,
            'business_id' => $businessId,
            'campaign_id' => $campaignId,
            'purpose' => $purpose,
            'phone' => (string) $digits,
            'status' => 'queued',
        ]);

        $error = match (true) {
            ! $this->settings->active() => 'WhatsApp is switched off.',
            ! $template->isApproved() => 'The template is not approved yet.',
            ShopCustomer::normalizePhone($phone) === null => 'Not a valid mobile number.',
            $template->category === 'MARKETING' && $this->optedOut((string) $digits) => 'This number asked not to get offers (STOP).',
            default => null,
        };
        if ($error !== null) {
            $message->update(['status' => 'failed', 'error' => $error]);

            return $message;
        }

        try {
            $values = $this->templates->resolve($template, $context, $typed);
            $id = $this->client->sendTemplate(
                phone: (string) $digits,
                name: $template->name,
                language: $template->language,
                headerValues: $values['header'],
                bodyValues: $values['body'],
                buttonValues: $values['buttons'],
                callbackData: $message->uuid,
            );
            $message->update(['status' => 'sent', 'interakt_message_id' => $id ?: null]);
        } catch (Throwable $e) {
            Log::warning('WhatsApp send failed', ['purpose' => $purpose, 'template' => $template->name, 'error' => $e->getMessage()]);
            $message->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $message;
    }

    public function optedOut(string $phone): bool
    {
        return DB::table('whatsapp_opt_outs')->where('phone', $phone)->exists();
    }

    /** The values a shop supplies to its templates. @return array<string, string|null> */
    public static function shopContext(Business $business): array
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return [
            'shop_name' => $business->name,
            'shop_slug' => $business->slug,
            'shop_link' => "{$base}/b/{$business->slug}",
            'shop_phone' => $business->whatsapp ?: $business->phone,
            'shop_image' => $business->displayCoverUrl($business->cover_path ? Storage::disk('public')->url($business->cover_path) : null),
        ];
    }
}
