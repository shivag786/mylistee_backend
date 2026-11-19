<?php

namespace App\Services\AiAdvisor;

use App\Enums\PromotionType;
use App\Events\AiCampaignApproved;
use App\Models\AiSuggestion;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Services\AiAssistantService;
use App\Services\ComboService;
use App\Services\PlanAccessService;
use App\Services\PromotionService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * AI Sales Manager (kind `sales`: offers, combos, discounts) and AI Customer
 * Growth Advisor (kind `growth`: win-back, first order, loyalty, reviews…).
 *
 * generate(): last-30-day aggregates → Gemini → AiAdvisorGuard → saved as
 * pending suggestions (the previous pending ones of that kind expire).
 * approve(): turns one into real promotions / a combo / a coupon through the
 * existing services, and for growth fires AiCampaignApproved (push).
 */
class AiAdvisorService
{
    public const MIN_ORDERS = 3;

    public function __construct(
        private readonly AiAssistantService $ai,
        private readonly AdvisorStatsService $stats,
        private readonly AiAdvisorGuard $guard,
        private readonly PromotionService $promotions,
        private readonly ComboService $combos,
        private readonly PlanAccessService $access,
    ) {}

    /** Needs the AI switched on with a Gemini key. */
    public function available(): bool
    {
        return $this->ai->enabled() && $this->ai->provider() === 'gemini';
    }

    public function refreshesLeft(Business $business, string $kind): int
    {
        return max(0, (int) config('ai_advisor.manual_refresh_per_day', 3) - (int) $this->counter()->get($this->refreshKey($business, $kind), 0));
    }

    /** The owner's "Refresh suggestions": generate() behind the daily throttle. */
    public function refresh(Business $business, string $kind): array
    {
        if ($this->refreshesLeft($business, $kind) <= 0) {
            throw ValidationException::withMessages(['refresh' => ['You have refreshed these ideas enough for today. New ideas come every night on their own.']]);
        }
        $out = $this->generate($business, $kind);
        $this->counter()->put($this->refreshKey($business, $kind), (int) $this->counter()->get($this->refreshKey($business, $kind), 0) + 1, now()->endOfDay());

        return $out;
    }

    /**
     * Ask the AI for fresh ideas and save the safe ones.
     *
     * @return list<AiSuggestion>
     *
     * @throws ValidationException when there is too little data
     * @throws RuntimeException when the AI fails or returns nothing usable
     */
    public function generate(Business $business, string $kind): array
    {
        $stats = $kind === AiSuggestion::KIND_SALES ? $this->stats->sales($business) : $this->stats->growth($business);
        if ($stats['orders'] < self::MIN_ORDERS) {
            throw ValidationException::withMessages(['orders' => [
                'AI ideas need at least '.self::MIN_ORDERS.' completed orders in the last '.$stats['windowDays'].' days. Keep billing on Listee and check back soon.',
            ]]);
        }

        $catalog = collect($kind === AiSuggestion::KIND_SALES ? $stats['catalog'] : $this->stats->catalog($business))->keyBy('id')->all();
        [$system, $prompt] = $kind === AiSuggestion::KIND_SALES
            ? $this->salesPrompt($business, $stats)
            : $this->growthPrompt($business, $stats, $catalog);

        $content = $this->ai->askGemini($system, [['text' => $prompt]], temperature: 0.7, timeout: 90);
        $json = $this->ai->decodeJson($content);
        $raw = $json['suggestions'] ?? (is_array($json) && array_is_list($json) ? $json : []);

        $clean = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $safe = $kind === AiSuggestion::KIND_SALES ? $this->guard->sales($row, $catalog) : $this->guard->growth($row);
            if ($safe !== null) {
                $clean[] = $safe;
            }
            if (count($clean) >= (int) config('ai_advisor.max_suggestions', 4)) {
                break;
            }
        }
        if ($clean === []) {
            Log::info('AI advisor returned nothing usable', ['business' => $business->id, 'kind' => $kind, 'content' => mb_substr($content, 0, 400)]);
            throw new RuntimeException('The AI could not come up with safe ideas this time. Please try again later.');
        }

        unset($stats['catalog']);
        $batch = (string) Str::uuid();

        return DB::transaction(function () use ($business, $kind, $clean, $stats, $batch): array {
            AiSuggestion::query()->where('business_id', $business->id)->where('kind', $kind)
                ->where('status', 'pending')->update(['status' => 'expired']);

            return array_map(fn (array $s) => AiSuggestion::create([
                'business_id' => $business->id,
                'kind' => $kind,
                'batch' => $batch,
                'type' => $s['type'],
                'title' => $s['title'],
                'message' => $s['message'] ?: null,
                'reasoning' => $s['reasoning'] ?: null,
                'payload' => array_diff_key($s, array_flip(['type', 'title', 'message', 'reasoning'])),
                'stats' => $stats,
                'status' => 'pending',
            ]), $clean);
        });
    }

    /**
     * Approve one suggestion. `$edits` may change the title, message, value,
     * minimum order, validity, time window or (growth) send time; the safety
     * rules run again on the result.
     *
     * @param  array<string, mixed>  $edits
     */
    public function approve(AiSuggestion $s, User $actor, array $edits = []): AiSuggestion
    {
        if ($s->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['This idea was already '.$s->status.'.']]);
        }
        $business = $s->business;

        return $s->kind === AiSuggestion::KIND_SALES
            ? $this->approveSales($s, $business, $actor, $edits)
            : $this->approveGrowth($s, $business, $actor, $edits);
    }

    public function dismiss(AiSuggestion $s, User $actor): AiSuggestion
    {
        if ($s->status === 'pending') {
            $s->update(['status' => 'dismissed', 'decided_by' => $actor->id, 'decided_at' => now()]);
        }

        return $s;
    }

    // ------------------------------------------------------------- approve

    /** @param  array<string, mixed>  $edits */
    private function approveSales(AiSuggestion $s, Business $business, User $actor, array $edits): AiSuggestion
    {
        $catalog = collect($this->stats->catalog($business))->keyBy('id')->all();
        $merged = array_merge(
            ['type' => $s->type, 'title' => $s->title, 'message' => $s->message, 'reasoning' => $s->reasoning],
            $s->payload ?? [],
            array_intersect_key($edits, array_flip(['title', 'message', 'value', 'minOrder', 'validityDays', 'timeFrom', 'timeTo', 'days', 'productIds', 'quantities'])),
        );
        $safe = $this->guard->sales($merged, $catalog);
        if ($safe === null) {
            throw ValidationException::withMessages(['suggestion' => ['These items are no longer on your menu, so this idea cannot be used. Refresh for new ideas.']]);
        }

        $start = now();
        $end = now()->addDays($safe['validityDays'])->endOfDay();
        $products = Product::query()->where('business_id', $business->id)->whereIn('id', $safe['productIds'])->get()->keyBy('id');

        $result = DB::transaction(function () use ($safe, $business, $actor, $start, $end, $products): array {
            $type = $safe['type'];

            if ($type === 'combo') {
                $this->needModule($business, 'combos', 'Combos');
                $combo = $this->combos->create($business, [
                    'name' => $safe['title'],
                    'combo_price' => $safe['value'],
                    'starts_at' => $start,
                    'ends_at' => $end,
                ], $products->values()->map(fn (Product $p) => ['product_id' => $p->uuid, 'quantity' => (int) ($safe['quantities'][$p->id] ?? 1)])->all(), null, $actor);

                return ['combo' => $combo->uuid, 'summary' => "Combo “{$combo->name}” at ₹".$this->n($safe['value']).' is live till '.$end->format('j M').'.'];
            }

            if (in_array($type, ['percent', 'flat'], true) && $safe['productIds'] === []) {
                $this->needModule($business, 'rewards', 'Coupons');
                $coupon = $this->coupon($business, $safe['title'], $type, $safe['value'], $safe['minOrder'], $end);

                return ['coupon' => $coupon->code, 'summary' => "Coupon {$coupon->code} is live till ".$end->format('j M').'. Share it with your customers.'];
            }

            $this->needModule($business, 'grow_sales', 'Offers');
            $uuids = [];
            foreach ($products as $product) {
                $data = [
                    'promotion_type' => match ($type) {
                        'happy_hour' => PromotionType::HappyHour->value,
                        'bogo' => PromotionType::Bogo->value,
                        'flat' => PromotionType::Flat->value,
                        default => PromotionType::Percentage->value,
                    },
                    'name' => $safe['title'],
                    'discount_type' => $type === 'flat' ? 'flat' : 'percentage',
                    'value' => $type === 'bogo' ? null : $safe['value'],
                    'buy_qty' => $safe['buyQty'],
                    'get_qty' => $safe['getQty'],
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'daily_start_time' => $safe['timeFrom'],
                    'daily_end_time' => $safe['timeTo'],
                ];
                $promo = $this->promotions->create($business, $data, $actor, $product);
                if ($safe['days'] !== []) {
                    $promo->config = array_merge($promo->config ?? [], ['days' => $safe['days']]);
                    $promo->save();
                }
                $uuids[] = $promo->uuid;
            }

            return ['promotions' => $uuids, 'summary' => count($uuids).' '.(count($uuids) === 1 ? 'offer is' : 'offers are').' live on your menu till '.$end->format('j M').'.'];
        });

        $s->update([
            'title' => $safe['title'],
            'message' => $safe['message'] ?: null,
            'payload' => array_diff_key($safe, array_flip(['type', 'title', 'message', 'reasoning'])),
            'status' => 'approved',
            'result' => $result,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        return $s->fresh();
    }

    /** @param  array<string, mixed>  $edits */
    private function approveGrowth(AiSuggestion $s, Business $business, User $actor, array $edits): AiSuggestion
    {
        $merged = array_merge(
            ['type' => $s->type, 'title' => $s->title, 'message' => $s->message, 'reasoning' => $s->reasoning],
            $s->payload ?? [],
            array_intersect_key($edits, array_flip(['title', 'pushTitle', 'message', 'segment', 'sendTime'])),
        );
        if (array_key_exists('offer', $edits)) {
            $merged['offer'] = $edits['offer']; // null removes the offer
        }
        $safe = $this->guard->growth($merged);
        if ($safe === null) {
            throw ValidationException::withMessages(['message' => ['Add a title and a message first.']]);
        }

        $result = DB::transaction(function () use ($safe, $business): array {
            $result = ['summary' => ''];
            if ($safe['offer'] !== null) {
                $this->needModule($business, 'rewards', 'Coupons');
                $end = now()->addDays($safe['offer']['validityDays'])->endOfDay();
                $coupon = $this->coupon($business, $safe['title'], $safe['offer']['kind'], $safe['offer']['value'], $safe['offer']['minOrder'], $end);
                $result['coupon'] = $coupon->code;
            }

            return $result;
        });

        // The push text carries the coupon code.
        $body = $safe['message'];
        if (isset($result['coupon'])) {
            $body = str_contains($body, '{CODE}') ? str_replace('{CODE}', $result['coupon'], $body) : rtrim($body).' Code: '.$result['coupon'];
        }
        $body = str_replace('{CODE}', '', $body);
        $sendAt = ($edits['sendNow'] ?? false) ? now() : $this->sendAt($safe['sendTime']);
        $result['summary'] = (isset($result['coupon']) ? "Coupon {$result['coupon']} created. " : '')
            .'The notification goes out '.($sendAt->lte(now()->addMinute()) ? 'now' : 'at '.$sendAt->copy()->setTimezone($this->tz())->format('g:i A, j M')).'.';

        $s->update([
            'title' => $safe['title'],
            'message' => $body,
            'payload' => array_diff_key($safe, array_flip(['type', 'title', 'message', 'reasoning'])),
            'status' => 'approved',
            'result' => $result,
            'send_at' => $sendAt,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        AiCampaignApproved::dispatch($s->fresh(), $actor);

        return $s->fresh();
    }

    /** Next time the push may go: the AI's best time (today or tomorrow), never in quiet hours (22:00–08:00). */
    private function sendAt(?string $time): Carbon
    {
        $now = now($this->tz());
        $at = $time !== null ? $now->copy()->setTimeFromTimeString($time) : $now->copy();
        if ($time !== null && $at->lt($now->copy()->subMinutes(5))) {
            $at->addDay();
        }
        if ($at->hour >= 22) {
            $at = $at->addDay()->setTime(8, 30);
        } elseif ($at->hour < 8) {
            $at->setTime(8, 30);
        }

        return $at->setTimezone(config('app.timezone'));
    }

    private function coupon(Business $business, string $title, string $kind, float $value, ?float $minOrder, Carbon $end): Coupon
    {
        do {
            $code = 'AI'.strtoupper(Str::random(5));
        } while (Coupon::query()->where('business_id', $business->id)->where('code', $code)->exists());

        return Coupon::create([
            'business_id' => $business->id,
            'kind' => Coupon::KIND_CODE,
            'code' => $code,
            'title' => mb_substr($title, 0, 80),
            'discount_type' => $kind === 'flat' ? 'flat' : 'percent',
            'value' => $value,
            'min_bill' => $minOrder,
            'per_customer_limit' => 1,
            'starts_at' => now(),
            'expires_at' => $end,
            'active' => true,
        ]);
    }

    private function needModule(Business $business, string $module, string $label): void
    {
        if (! $this->access->allows($business, $module)) {
            throw ValidationException::withMessages(['plan' => ["{$label} are not in your plan. Upgrade to use this idea."]]);
        }
    }

    // ------------------------------------------------------------- prompts

    /** @return array{0: string, 1: string} */
    private function salesPrompt(Business $business, array $stats): array
    {
        $max = $this->guard->maxPercent();
        $ratio = (int) round((float) config('ai_advisor.rules.min_combo_price_ratio', 0.8) * 100);
        $catalog = implode("\n", array_map(fn ($p) => "{$p['id']} | {$p['name']} | ₹{$p['price']}".($p['category'] ? " | {$p['category']}" : ''), $stats['catalog']));
        $data = $stats;
        unset($data['catalog']);

        $system = 'You are the sales manager of a small Indian shop on Listee (local ordering + rewards app). '
            .'You study the shop’s real sales numbers and suggest offers that grow revenue without hurting profit. '
            .'Use only the data given. Reply with JSON only.';

        $prompt = "Shop: {$business->name}".($business->category ? " ({$business->category->name})" : '').($business->city ? ", {$business->city}" : '').".\n"
            ."Last {$stats['windowDays']} days of completed orders (aggregated):\n".json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n"
            ."Menu (id | name | price | section):\n{$catalog}\n\n"
            .'Give 3 or 4 different suggestions to raise sales. Ideas: pair items that sell together into a combo, push slow items '
            .'with a weak hour or weekday, a happy hour in the slowest hours, a bill-level offer to raise the average order value. '
            ."Do not repeat an offer that is already active. A bill-level offer (product_ids []) works every day at all hours — for a day- or hour-specific deal, pick items.\n"
            ."Rules: no discount above {$max}%; a combo costs at least {$ratio}% of its items’ total; use only menu ids above; "
            .'BOGO must give at most '.$max.'% free (e.g. Buy 3 Get 1).'."\n"
            .'Each suggestion: "type" (percent | flat | combo | bogo | happy_hour), "title" (short, English), '
            .'"message" (what customers see, Hinglish in Roman script, max 120 characters, e.g. "Shaam 4–6 baje sabhi starters pe 15% off!"), '
            .'"reasoning" (1–2 sentences quoting the numbers above), "product_ids" (menu ids; [] for a bill-level offer), '
            .'"value" (percent number, ₹ amount, or the combo price — the message must use exactly this number), "items" (combo only: [{"id":5,"qty":2}] for quantities), "min_order" (₹ or null), "buy_qty"/"get_qty" (BOGO only), '
            .'"days" (e.g. ["mon","tue"] or [] for every day; item offers only), "time_window" (e.g. "16:00-18:00" or null; item offers only), '
            .'"validity_days" (1–30), "expected_impact" (e.g. "+10–15 orders a week").'."\n"
            .'Shape: {"suggestions":[{...}]}';

        return [$system, $prompt];
    }

    /** @return array{0: string, 1: string} */
    private function growthPrompt(Business $business, array $stats, array $catalog): array
    {
        $max = $this->guard->maxPercent();
        $items = implode(', ', array_map(fn ($p) => "{$p['name']} ₹{$p['price']}", array_slice($catalog, 0, 30)));

        $system = 'You are a customer growth advisor for a small Indian shop on Listee (local ordering + rewards app with '
            .'push notifications, coupons and loyalty coins). You find who to bring back and how, from aggregated numbers only. '
            .'Be respectful to customers — no spammy or pushy text. Reply with JSON only.';

        $prompt = "Shop: {$business->name}".($business->category ? " ({$business->category->name})" : '').($business->city ? ", {$business->city}" : '').".\n"
            ."Customer numbers for the last {$stats['windowDays']} days (aggregated, no personal data):\n".json_encode($stats, JSON_UNESCAPED_UNICODE)."\n"
            ."Hours are 0–23 local time. Some menu items: {$items}\n\n"
            .'Give 3 or 4 suggestions, each a different type from: win_back (bring lapsed customers back), first_order (turn followers '
            .'into first-time buyers), loyalty (reward regulars), review_request (ask happy customers for a review), slow_hour_push '
            .'(fill the slowest hours), item_highlight (feature a strong item). Pick what the numbers need most.'."\n"
            .'Each: "type", "title" (short, English, for the owner), "segment" (one of: lapsed, followers, customers, everyone), '
            .'"push_title" (max 45 characters, Hinglish), "message" (the notification text, Hinglish in Roman script, max 150 '
            .'characters, friendly; if there is an offer write {CODE} where the coupon code goes and state the offer exactly — same % or ₹, no time limits, it works any time), '
            ."\"offer\" (optional: {\"type\":\"percent\"|\"flat\",\"value\":number,\"min_order\":number|null,\"validity_days\":number} — at most {$max}% — or null), "
            .'"best_send_time" (HH:MM between 08:00 and 21:30, from the peak hours), "reasoning" (quote the numbers), '
            .'"expected_result" (e.g. "8–12 lapsed customers come back").'."\n"
            .'Shape: {"suggestions":[{...}]}';

        return [$system, $prompt];
    }

    // ------------------------------------------------------------- helpers

    private function tz(): string
    {
        return (string) config('app.business_timezone', 'Asia/Kolkata');
    }

    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function counter(): Repository
    {
        return Cache::store(config('cache.limiter') ?: 'file');
    }

    private function refreshKey(Business $business, string $kind): string
    {
        return 'ai-advisor-refresh:'.$business->id.':'.$kind.':'.now($this->tz())->format('Y-m-d');
    }
}
