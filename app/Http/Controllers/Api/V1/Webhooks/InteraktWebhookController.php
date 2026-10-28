<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ShopCustomer;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Interakt webhook: message delivery status, template approval, and replies
 * (STOP / START for offers).
 *
 * Set it up in Interakt → Settings → Developer Setting → Webhook with this
 * URL and a secret key, and save the same secret in admin → WhatsApp. The
 * `Interakt-Signature` header ("sha256=<hex HMAC of the raw body>") is the only
 * authentication, so no secret or a bad signature is refused — fail closed.
 */
class InteraktWebhookController extends Controller
{
    /** Later states never step back to earlier ones (a late "sent" after "read"). */
    private const RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    public function __construct(private readonly WhatsAppSettings $settings) {}

    /** POST /webhooks/interakt */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = $this->settings->webhookSecret();
        $raw = $request->getContent();
        $given = (string) $request->header('Interakt-Signature', '');
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        if ($secret === '' || ! hash_equals($expected, $given)) {
            Log::warning('Rejected Interakt webhook with an invalid signature', ['ip' => $request->ip()]);

            return ApiResponse::error('Invalid signature.', status: 401);
        }

        $type = (string) $request->input('type', '');
        $data = (array) $request->input('data', []);

        match (true) {
            str_starts_with($type, 'message_api_') => $this->messageStatus(substr($type, strlen('message_api_')), $data),
            $type === 'message_template_status_update' => $this->templateStatus($data),
            $type === 'message_received' => $this->reply($data),
            default => null,
        };

        return ApiResponse::success(null, 'OK');
    }

    private function messageStatus(string $status, array $data): void
    {
        $id = $data['message']['id'] ?? null;
        if (! is_string($id) || ! array_key_exists($status, self::RANK)) {
            return;
        }

        $message = WhatsAppMessage::query()->where('interakt_message_id', $id)->first();
        if ($message === null || self::RANK[$status] <= (self::RANK[$message->status] ?? 0)) {
            return;
        }

        $error = null;
        if ($status === 'failed') {
            $reason = $data['message']['channel_failure_reason'] ?? $data['message']['meta_data']['failed_reason'] ?? null;
            $error = is_string($reason) ? mb_substr($reason, 0, 500) : 'WhatsApp could not deliver it.';
        }
        $message->update(['status' => $status, 'error' => $error ?? $message->error]);
    }

    private function templateStatus(array $data): void
    {
        $status = strtoupper((string) ($data['event'] ?? ''));
        if ($status === '') {
            return;
        }

        $template = WhatsAppTemplate::query()
            ->where('wa_template_id', (string) ($data['message_template_id'] ?? '-'))
            ->orWhere('name', (string) ($data['message_template_name'] ?? '-'))
            ->first();

        $template?->update([
            'status' => $status,
            'rejection_reason' => $status === 'REJECTED' ? (mb_substr((string) ($data['reason'] ?? ''), 0, 500) ?: null) : null,
        ]);
    }

    /** A customer replied: STOP opts the number out of offers, START back in. */
    private function reply(array $data): void
    {
        $text = strtoupper(trim((string) ($data['message']['message'] ?? $data['message']['text'] ?? '')));
        $phone = ShopCustomer::normalizePhone((string) ($data['customer']['channel_phone_number'] ?? $data['customer']['phone_number'] ?? ''));
        if ($phone === null) {
            return;
        }

        if (in_array($text, ['STOP', 'UNSUBSCRIBE', 'STOP OFFERS'], true)) {
            DB::table('whatsapp_opt_outs')->insertOrIgnore(['phone' => $phone, 'created_at' => now()]);
        } elseif ($text === 'START') {
            DB::table('whatsapp_opt_outs')->where('phone', $phone)->delete();
        }
    }
}
