<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppCampaign;
use App\Models\Business;
use App\Models\ShopCustomer;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\PlanAccessService;
use App\Services\ShopPushService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A shop's WhatsApp offers: the owner picks one of the admin's ready-made,
 * Meta-approved templates, types their offer into its blanks, and sends it to
 * people connected to the shop — followers, past customers, or the shop's own
 * customer book.
 *
 * Every message costs money and a WhatsApp number that spams gets blocked, so
 * the guard rails are tighter than for push: the plan's monthly message count,
 * one send a day, quiet hours, a few offers per number per month, and nobody
 * who replied STOP.
 */
class WhatsAppCampaignService
{
    public const AUDIENCES = ShopPushService::AUDIENCES + ['book' => 'Your customer book'];

    /** Offers one number can get from one shop in a month. */
    public const PER_PHONE_PER_MONTH = 4;

    private const QUIET_FROM = 21;

    private const QUIET_UNTIL = 9;

    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppTemplateService $templates,
        private readonly WhatsAppSender $sender,
        private readonly ShopPushService $push,
        private readonly PlanAccessService $access,
    ) {}

    /** @return array<string, mixed> everything the owner's WhatsApp screen needs */
    public function summary(Business $business): array
    {
        $limit = $business->currentPlan()?->whatsapp_per_month;
        $used = $this->usedThisMonth($business);

        $audiences = [];
        foreach (self::AUDIENCES as $key => $label) {
            $audiences[] = ['key' => $key, 'label' => $label, 'count' => $this->recipients($business, $key)->count()];
        }

        return [
            'blockedReason' => $this->blockedReason($business, $limit, $used),
            'quota' => ['limit' => $limit, 'used' => $used, 'remaining' => $limit === null ? null : max(0, $limit - $used)],
            'perPhoneLimit' => self::PER_PHONE_PER_MONTH,
            'shop' => WhatsAppSender::shopContext($business),
            'templates' => $this->shopTemplates()->map(fn (WhatsAppTemplate $t) => [
                'id' => $t->uuid,
                'name' => $t->display_name ?: $t->name,
                'headerFormat' => $t->header_format,
                'header' => $t->header,
                'headerMediaUrl' => $t->header_media_url,
                'body' => $t->body,
                'footer' => $t->footer,
                'buttons' => $t->buttons ?? [],
                'variables' => [
                    'header' => $t->variables['header'] ?? null,
                    'body' => array_values((array) ($t->variables['body'] ?? [])),
                    'buttons' => (object) ($t->variables['buttons'] ?? []),
                ],
                'fields' => $this->templates->ownerFields($t),
            ])->values()->all(),
            'audiences' => $audiences,
            'recent' => WhatsAppCampaign::query()->with('template:id,name,display_name')
                ->where('business_id', $business->id)->latest('id')->limit(10)->get()
                ->map(fn (WhatsAppCampaign $c) => [
                    'id' => $c->uuid,
                    'template' => $c->template?->display_name ?: $c->template?->name,
                    'audience' => self::AUDIENCES[$c->audience] ?? $c->audience,
                    'recipients' => $c->recipients,
                    'sent' => $c->sent,
                    'failed' => $c->failed,
                    'finished' => $c->finished_at !== null,
                    'createdAt' => $c->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Send an offer. Delivery runs after the response.
     *
     * @param  array{template: string, audience: string, values?: array<string, string>, limit?: ?int}  $data
     */
    public function send(Business $business, User $actor, array $data): WhatsAppCampaign
    {
        $limit = $business->currentPlan()?->whatsapp_per_month;
        $used = $this->usedThisMonth($business);
        if (($reason = $this->blockedReason($business, $limit, $used)) !== null) {
            throw ValidationException::withMessages(['whatsapp' => [$reason]]);
        }

        $template = $this->shopTemplates()->firstWhere('uuid', $data['template']);
        if ($template === null) {
            throw ValidationException::withMessages(['template' => ['Pick one of the ready-made templates.']]);
        }

        $values = [];
        foreach ($this->templates->ownerFields($template) as $field) {
            $value = trim((string) ($data['values'][$field['key']] ?? ''));
            if ($value === '') {
                throw ValidationException::withMessages(["values.{$field['key']}" => ["Fill in “{$field['label']}”."]]);
            }
            // WhatsApp rejects new lines and long runs of spaces inside a blank.
            $values[$field['key']] = mb_substr(preg_replace('/\s+/', ' ', $value), 0, 200);
        }

        $cap = $limit === null ? PHP_INT_MAX : $limit - $used;
        $take = min($cap, max(1, (int) ($data['limit'] ?? PHP_INT_MAX)));
        $capped = $this->capped($business)->flip();
        $recipients = $this->recipients($business, $data['audience'])
            ->reject(fn ($r) => $capped->has($r['phone']))
            ->reject(fn ($r) => $this->sender->optedOut($r['phone']))
            ->take($take)
            ->values();

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['whatsapp' => [
                $this->recipients($business, $data['audience'])->isEmpty()
                    ? 'Nobody in this group has a mobile number yet.'
                    : 'These customers already got all the offers they can this month.',
            ]]);
        }

        $campaign = WhatsAppCampaign::create([
            'business_id' => $business->id,
            'template_id' => $template->id,
            'audience' => $data['audience'],
            'values' => $values,
            'recipients' => $recipients->count(),
            'created_by' => $actor->id,
        ]);

        SendWhatsAppCampaign::dispatchAfterResponse($campaign->id, $recipients->all());

        return $campaign;
    }

    /** Approved marketing templates the admin made available to shops. @return Collection<int, WhatsAppTemplate> */
    public function shopTemplates(): Collection
    {
        return WhatsAppTemplate::query()->approved()->where('for_shops', true)->orderBy('display_name')->get();
    }

    /**
     * The people in an audience who have a valid mobile number, newest first,
     * one entry per number.
     *
     * @return Collection<int, array{phone: string, name: ?string}>
     */
    public function recipients(Business $business, string $audience): Collection
    {
        $rows = $audience === 'book'
            ? ShopCustomer::query()->where('business_id', $business->id)->latest('id')->get(['name', 'phone'])
            : $this->push->audienceQuery($business, $audience)->whereNotNull('phone')->latest('id')->get(['name', 'phone']);

        return $rows
            ->map(fn ($row) => ['phone' => ShopCustomer::normalizePhone($row->phone), 'name' => $row->name])
            ->filter(fn ($r) => $r['phone'] !== null)
            ->unique('phone')
            ->values();
    }

    /** Messages the shop's offers used this month (failed ones don't count). */
    public function usedThisMonth(Business $business): int
    {
        return WhatsAppMessage::query()
            ->where('business_id', $business->id)
            ->where('purpose', 'campaign')
            ->where('status', '!=', 'failed')
            ->where('created_at', '>=', $this->localStartOf('month'))
            ->count();
    }

    private function blockedReason(Business $business, ?int $limit, int $used): ?string
    {
        if (! $this->settings->active()) {
            return 'WhatsApp offers are not switched on yet.';
        }
        if (! $this->access->allows($business, 'whatsapp')) {
            return 'Your plan does not include WhatsApp offers. Upgrade to send them.';
        }
        if ($this->shopTemplates()->isEmpty()) {
            return 'No WhatsApp templates are ready yet. They appear here once approved.';
        }
        if ($limit !== null && $used >= $limit) {
            return $limit === 0
                ? 'Your plan does not include WhatsApp messages. Upgrade to send them.'
                : "All {$limit} WhatsApp messages your plan allows this month are used.";
        }

        $local = now(config('app.business_timezone'));
        if ($local->hour >= self::QUIET_FROM || $local->hour < self::QUIET_UNTIL) {
            return 'WhatsApp offers go out between 9 AM and 9 PM so customers aren’t disturbed.';
        }
        if (WhatsAppCampaign::query()->where('business_id', $business->id)->where('created_at', '>=', $this->localStartOf('day'))->exists()) {
            return 'One WhatsApp offer a day keeps customers from blocking you. Send again tomorrow.';
        }

        return null;
    }

    /** Numbers that already got the monthly per-number cap of offers. @return Collection<int, string> */
    private function capped(Business $business): Collection
    {
        return DB::table('whatsapp_messages')
            ->where('business_id', $business->id)
            ->where('purpose', 'campaign')
            ->where('status', '!=', 'failed')
            ->where('created_at', '>=', $this->localStartOf('month'))
            ->groupBy('phone')
            ->havingRaw('count(*) >= ?', [self::PER_PHONE_PER_MONTH])
            ->pluck('phone');
    }

    private function localStartOf(string $unit): Carbon
    {
        return now(config('app.business_timezone'))->startOf($unit)->utc();
    }
}
