<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Business;
use App\Models\OrderItem;
use App\Services\AiAdvisor\AdvisorStatsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The shop owner's AI helper (vendor app → AI assistant): writes dish
 * descriptions, offer and combo ideas, notification copy, sales tips and
 * review replies, through Google Gemini (free tier) or OpenAI.
 *
 * Everything comes back as a short list of suggestions the owner taps to use;
 * nothing is saved or sent by itself.
 *
 * Admin → Settings → AI assistant holds the switch, the provider, its key, the model
 * and the monthly limit per shop; the plan decides which shops get it
 * (module `ai_assistant`).
 */
class AiAssistantService
{
    /** What the owner can ask for, with what each needs from them. */
    public const KINDS = [
        'product_description' => 'Dish description',
        'offer_ideas' => 'Offer ideas',
        'push_message' => 'Notification message',
        'combo_ideas' => 'Combo ideas',
        'sales_tips' => 'Sales tips',
        'review_reply' => 'Review reply',
        'ask' => 'Ask your sales manager',
    ];

    public function __construct(private readonly SettingService $settings) {}

    /**
     * Gemini models tried, in order, after the chosen one when it is
     * overloaded or gone (free tier; the lite models answer in seconds).
     */
    public const GEMINI_FALLBACK_MODELS = ['gemini-3.1-flash-lite', 'gemini-3.5-flash-lite'];

    public function provider(): string
    {
        return $this->settings->get('aiProvider') === 'openai' ? 'openai' : 'gemini';
    }

    public function enabled(): bool
    {
        $key = $this->provider() === 'openai' ? 'openaiApiKey' : 'geminiApiKey';

        return (bool) $this->settings->get('aiEnabled', false) && filled($this->settings->get($key));
    }

    /** Shown as the limit when a plan has unlimited AI answers. */
    public const UNLIMITED = 100000;

    /**
     * AI answers a month for this shop's plan; null = unlimited. A shop with
     * no plan at all falls back to the admin's old single limit.
     */
    public function planLimit(Business $business): ?int
    {
        $plan = $business->currentPlan();
        if ($plan === null) {
            return max(0, (int) $this->settings->get('aiMonthlyLimit', 100));
        }

        return $plan->ai_answers_per_month === null ? null : max(0, (int) $plan->ai_answers_per_month);
    }

    /** Ideas in one answer (admin setting, 1–10). */
    public function ideasPerAnswer(): int
    {
        return min(10, max(1, (int) $this->settings->get('aiIdeasPerAnswer', 3)));
    }

    /**
     * Where the shop stands this month. `used`/`limit` include add-on answers
     * (limit = plan answers + add-on answers used + add-on answers left), so
     * `limit - used` is everything still available — older app builds that
     * only read these two keep working.
     *
     * @return array{planLimit: ?int, planUsed: int, addonUsed: int, credits: int, left: ?int, used: int, limit: int, unlimited: bool}
     */
    public function usage(Business $business): array
    {
        $rows = AiUsage::query()->where('business_id', $business->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereIn('source', ['plan', 'addon'])
            ->selectRaw('source, COUNT(*) as n')->groupBy('source')->pluck('n', 'source');
        $planUsed = (int) ($rows['plan'] ?? 0);
        $addonUsed = (int) ($rows['addon'] ?? 0);
        $credits = (int) Business::query()->whereKey($business->id)->value('ai_credits');
        $planLimit = $this->planLimit($business);
        $left = $planLimit === null ? null : max(0, $planLimit - $planUsed) + $credits;

        return [
            'planLimit' => $planLimit,
            'planUsed' => $planUsed,
            'addonUsed' => $addonUsed,
            'credits' => $credits,
            'left' => $left,
            'used' => $planUsed + $addonUsed,
            'limit' => $planLimit === null ? self::UNLIMITED : $planLimit + $addonUsed + $credits,
            'unlimited' => $planLimit === null,
        ];
    }

    /** All AI answers used this month (plan + add-on). */
    public function usedThisMonth(Business $business): int
    {
        return $this->usage($business)['used'];
    }

    /** @deprecated kept for older callers: the shop's total available this month. */
    public function monthlyLimit(?Business $business = null): int
    {
        return $business === null ? max(0, (int) $this->settings->get('aiMonthlyLimit', 100)) : $this->usage($business)['limit'];
    }

    /** Log one AI call (any source) for the admin's usage report. */
    public function record(?Business $business, string $kind, string $source): void
    {
        AiUsage::query()->create(['business_id' => $business?->id, 'kind' => mb_substr($kind, 0, 40), 'source' => $source]);
    }

    /**
     * Count one owner AI answer: from the month's plan answers first, then
     * from the add-on answers.
     */
    private function charge(Business $business, string $kind): void
    {
        DB::transaction(function () use ($business, $kind): void {
            $u = $this->usage($business);
            $source = 'plan';
            if ($u['planLimit'] !== null && $u['planUsed'] >= $u['planLimit']) {
                $took = Business::query()->whereKey($business->id)->where('ai_credits', '>', 0)->decrement('ai_credits');
                $source = $took > 0 ? 'addon' : 'plan';
            }
            $this->record($business, $kind, $source);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException when the input is unusable or the limit is reached
     * @throws RuntimeException when OpenAI fails
     */
    public function generate(Business $business, string $kind, array $input): array
    {
        $left = $this->usage($business)['left'];
        if ($left !== null && $left <= 0) {
            throw ValidationException::withMessages([
                'limit' => ['You have used all your AI answers for this month. Buy an AI pack to keep going, or wait — they refresh on the 1st.'],
            ]);
        }

        [$task, $shape] = $this->task($business, $kind, $input);

        $system = 'You are a friendly marketing helper for small Indian food and retail shops on Listee, '
            .'a local ordering and rewards app. Write short, warm, concrete copy in simple English that a '
            .'shop owner can use as-is. Use ₹ for prices. No hashtags, no made-up facts about the shop. '
            .'Reply with JSON only, exactly in this shape: '.$shape;

        $content = $this->provider() === 'openai'
            ? $this->askOpenAi($system, $task)
            : $this->askGemini($system, [['text' => $task]]);

        $json = $this->decodeJson($content);
        $items = is_array($json) ? ($json['suggestions'] ?? (array_is_list($json) ? $json : null)) : null;
        // A plain string suggestion ("Thanks for…") becomes {text: "…"}.
        $items = is_array($items) ? array_values(array_filter(array_map(
            fn ($s) => is_string($s) && trim($s) !== '' ? ['text' => trim($s)] : $s,
            $items,
        ), 'is_array')) : [];
        if ($items === []) {
            Log::warning('AI returned no usable suggestions', ['kind' => $kind, 'content' => mb_substr($content, 0, 500)]);
            throw new RuntimeException('The AI did not return suggestions. Please try again.');
        }

        $this->charge($business, $kind);

        // Never more ideas than the admin allows in one answer.
        return array_slice($items, 0, $kind === 'ask' ? 1 : $this->ideasPerAnswer());
    }

    /** OpenAI Chat Completions; returns the JSON text the model wrote. */
    private function askOpenAi(string $system, string $task): string
    {
        $response = Http::withToken((string) $this->settings->get('openaiApiKey'))
            ->timeout(45)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => (string) ($this->settings->get('openaiModel') ?: 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $task],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.8,
            ]);

        $this->failIfBad($response, 'OpenAI', [401]);

        return (string) $response->json('choices.0.message.content');
    }

    /**
     * Google Gemini generateContent (free tier on AI Studio keys); returns the
     * JSON text. `$parts` are Gemini content parts: {text} and, for photos and
     * PDFs, {inline_data: {mime_type, data(base64)}}. An overloaded or retired
     * model (404/429/5xx) is retried on the fallbacks.
     *
     * @param  list<array<string, mixed>>  $parts
     *
     * @throws RuntimeException when every model fails
     */
    public function askGemini(string $system, array $parts, float $temperature = 0.8, int $timeout = 60, ?int $maxOutputTokens = null): string
    {
        $chosen = (string) ($this->settings->get('geminiModel') ?: self::GEMINI_FALLBACK_MODELS[0]);
        $models = array_values(array_unique([$chosen, ...self::GEMINI_FALLBACK_MODELS]));

        foreach ($models as $i => $model) {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => (string) $this->settings->get('geminiApiKey')])
                    ->timeout($timeout)
                    ->acceptJson()
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                        'systemInstruction' => ['parts' => [['text' => $system]]],
                        'contents' => [['role' => 'user', 'parts' => $parts]],
                        'generationConfig' => array_filter([
                            'responseMimeType' => 'application/json',
                            'temperature' => $temperature,
                            'maxOutputTokens' => $maxOutputTokens,
                        ], fn ($v) => $v !== null),
                    ]);
            } catch (ConnectionException $e) {
                Log::warning('Gemini connection failed', ['model' => $model, 'error' => $e->getMessage()]);
                if ($i < count($models) - 1) {
                    continue;
                }
                throw new RuntimeException('The AI is busy right now. Please try again in a minute.');
            }

            if (in_array($response->status(), [404, 429, 500, 503], true) && $i < count($models) - 1) {
                Log::info('Gemini model unavailable, using fallback', ['model' => $model, 'status' => $response->status()]);

                continue;
            }

            $this->failIfBad($response, 'Gemini', [400, 401, 403]);

            // Skip "thought" parts some models return; join the answer parts.
            return collect($response->json('candidates.0.content.parts', []))
                ->reject(fn ($p) => ! empty($p['thought']))
                ->pluck('text')
                ->implode('');
        }

        throw new RuntimeException('The AI is busy right now. Please try again in a minute.');
    }

    /** @param  list<int>  $keyErrors statuses that mean the key itself is wrong */
    private function failIfBad(Response $response, string $name, array $keyErrors): void
    {
        if ($response->successful()) {
            return;
        }
        Log::warning($name.' request failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
        $badKey = in_array($response->status(), $keyErrors, true)
            && ($response->status() !== 400 || str_contains($response->body(), 'API_KEY'));
        throw new RuntimeException($badKey
            ? 'The AI key set by Listee is not working. Please tell Listee support.'
            : 'The AI is busy right now. Please try again in a minute.');
    }

    /**
     * The model's JSON as an array (null when it is not JSON). Some models wrap
     * it in ```json fences despite JSON mode.
     *
     * @return array<mixed>|null
     */
    public function decodeJson(string $text): ?array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text) ?? $text;
        }
        $json = json_decode($text, true);

        return is_array($json) ? $json : null;
    }

    /**
     * The prompt for one kind, and the JSON shape expected back.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string}
     */
    private function task(Business $business, string $kind, array $input): array
    {
        $shop = $this->shopContext($business);
        $n = $this->ideasPerAnswer();
        $note = trim((string) ($input['note'] ?? ''));
        $extra = $note !== '' ? "\nThe owner adds: {$note}" : '';

        return match ($kind) {
            'product_description' => [
                $this->need($input, 'name', 'Enter the dish name first.')
                    ."\nWrite {$n} different menu descriptions (max 25 words each) for this item at {$shop}"
                    .' Item: '.$input['name'].(filled($input['price'] ?? null) ? ' (₹'.$input['price'].')' : '').'.'.$extra,
                '{"suggestions":[{"text":"..."}]}',
            ],
            'offer_ideas' => [
                "Suggest {$n} offers that would bring more customers to {$shop} Mix spin-wheel prizes, happy hours and bill discounts. "
                .'Each needs a short title, one-line description, a type (percentage, flat, free_item, bogo, happy_hour or cashback) '
                .'and a reward value like "20%", "₹50 off" or "Free chai".'.$extra,
                '{"suggestions":[{"title":"...","description":"...","type":"percentage","rewardValue":"20%"}]}',
            ],
            'push_message' => [
                "Write {$n} push notifications {$shop} could send to its followers. Title max 40 characters, body max 110 characters, "
                .'friendly with one emoji at most.'.(filled($input['goal'] ?? null) ? ' Goal: '.$input['goal'].'.' : '').$extra,
                '{"suggestions":[{"title":"...","body":"..."}]}',
            ],
            'combo_ideas' => [
                "Suggest {$n} combo deals for {$shop} using only items from its menu. Give each a catchy name, the items, "
                .'and a combo price about 10–15% below the items added up.'.$extra,
                '{"suggestions":[{"name":"...","items":["..."],"price":199,"why":"..."}]}',
            ],
            'sales_tips' => [
                "Give {$n} practical, low-cost tips to grow sales this week at {$shop} Each with a short title and 1–2 sentences."
                .' Mention Listee tools where they fit (spin & win, combos, happy hours, push notifications, QR at the counter).'.$extra,
                '{"suggestions":[{"title":"...","body":"..."}]}',
            ],
            'review_reply' => [
                $this->need($input, 'review', 'Paste the review first.')
                    ."\nWrite {$n} short, polite replies (max 45 words) from the owner of {$shop} to this "
                    .(filled($input['rating'] ?? null) ? $input['rating'].'-star ' : '').'review: "'.mb_substr((string) $input['review'], 0, 600).'"',
                '{"suggestions":[{"text":"..."}]}',
            ],
            'ask' => [
                $this->need($input, 'question', 'Type your question first.')
                    ."\nYou are now the shop's sales manager. The owner of {$shop} asks: \"".mb_substr(trim((string) $input['question']), 0, 500).'"'
                    ."\nTheir numbers (last 30 days, paid and completed orders only): ".$this->numbers($business)
                    ."\nAnswer in the same language and style the owner used (Hindi, Hinglish or English). Be direct and practical: "
                    .'first the answer in 2–4 short sentences built on the numbers above (say plainly when there is not enough data yet), '
                    .'then up to 3 next steps they can do today in Listee (combos, offers, happy hours, spin & win, push notifications, '
                    .'menu photos and descriptions, table QR). Never invent numbers, customers or reviews. Max 140 words in total.',
                '{"suggestions":[{"text":"the answer","steps":["...","..."]}]}',
            ],
            default => throw ValidationException::withMessages(['kind' => ['Unknown request.']]),
        };
    }

    /** "Chai Point, a Cafe in Pune. Best sellers: Masala chai (₹20), …" */
    private function shopContext(Business $business): string
    {
        $business->loadMissing('category');
        $top = OrderItem::query()
            ->whereHas('order', fn ($o) => $o->where('business_id', $business->id)->where('status', '!=', 'cancelled'))
            ->selectRaw('name, SUM(quantity) as qty, MAX(unit_price) as price')
            ->groupBy('name')
            ->orderByDesc('qty')
            ->limit(6)
            ->get()
            ->map(fn ($r) => $r->name.' (₹'.rtrim(rtrim(number_format((float) $r->price, 2, '.', ''), '0'), '.').')')
            ->all();
        $menu = $business->products()->where('is_visible', true)->limit(25)->pluck('name')->all();

        return trim($business->name
            .($business->category ? ', a '.$business->category->name : '')
            .($business->city ? ' in '.$business->city : '').'.'
            .($top !== [] ? ' Best sellers: '.implode(', ', $top).'.' : '')
            .($menu !== [] ? ' Menu includes: '.implode(', ', $menu).'.' : ''));
    }

    /** The shop's own sales numbers, compact, for the "ask" kind. */
    private function numbers(Business $business): string
    {
        try {
            $stats = app(AdvisorStatsService::class);
            $sales = $stats->sales($business);
            $growth = $stats->growth($business);
        } catch (\Throwable $e) {
            report($e);

            return 'not available.';
        }
        $items = fn ($list) => collect($list)->take(8)->map(fn ($i) => $i['name'].' ×'.$i['qty'])->implode(', ');

        return json_encode([
            'orders' => $sales['orders'] ?? 0,
            'salesRupees' => $sales['revenue'] ?? 0,
            'averageBill' => $sales['aov'] ?? 0,
            'bestSellers' => $items($sales['topItems'] ?? []),
            'slowSellers' => $items($sales['bottomItems'] ?? []),
            'ordersByWeekday' => collect($sales['byWeekday'] ?? [])->mapWithKeys(fn ($d) => [$d['day'] => $d['orders']])->all(),
            'busyHours' => $growth['peakHours'] ?? [],
            'slowHours' => $growth['slowHours'] ?? [],
            'oftenOrderedTogether' => collect($sales['topPairs'] ?? [])->take(5)->map(fn ($p) => $p['a'].' + '.$p['b'])->all(),
            'customers' => $growth['uniqueCustomers'] ?? 0,
            'repeatRatePercent' => $growth['repeatRatePercent'] ?? 0,
            'lapsedCustomers' => $growth['lapsedCustomers'] ?? 0,
            'followers' => $growth['followers'] ?? 0,
            'rating' => $growth['rating'] ?? null,
            'menuItems' => count($sales['catalog'] ?? []),
            'liveOffers' => $sales['activeOffers'] ?? [],
        ], JSON_UNESCAPED_UNICODE) ?: 'not available.';
    }

    /** @param  array<string, mixed>  $input */
    private function need(array $input, string $key, string $message): string
    {
        if (! filled($input[$key] ?? null)) {
            throw ValidationException::withMessages([$key => [$message]]);
        }

        return '';
    }
}
