<?php

namespace App\Services;

use App\Models\Business;
use App\Models\OrderItem;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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

    public function monthlyLimit(): int
    {
        return max(0, (int) $this->settings->get('aiMonthlyLimit', 100));
    }

    public function usedThisMonth(Business $business): int
    {
        return (int) $this->counter()->get($this->usageKey($business), 0);
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
        if ($this->usedThisMonth($business) >= $this->monthlyLimit()) {
            throw ValidationException::withMessages([
                'limit' => ['You have used all your AI suggestions for this month. They refresh on the 1st.'],
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

        $this->counter()->put($this->usageKey($business), $this->usedThisMonth($business) + 1, now()->endOfMonth()->addDay());

        return $items;
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
    public function askGemini(string $system, array $parts, float $temperature = 0.8, int $timeout = 60): string
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
                        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => $temperature],
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
        $note = trim((string) ($input['note'] ?? ''));
        $extra = $note !== '' ? "\nThe owner adds: {$note}" : '';

        return match ($kind) {
            'product_description' => [
                $this->need($input, 'name', 'Enter the dish name first.')
                    ."\nWrite 3 different menu descriptions (max 25 words each) for this item at {$shop}"
                    .' Item: '.$input['name'].(filled($input['price'] ?? null) ? ' (₹'.$input['price'].')' : '').'.'.$extra,
                '{"suggestions":[{"text":"..."}]}',
            ],
            'offer_ideas' => [
                "Suggest 4 offers that would bring more customers to {$shop} Mix spin-wheel prizes, happy hours and bill discounts. "
                .'Each needs a short title, one-line description, a type (percentage, flat, free_item, bogo, happy_hour or cashback) '
                .'and a reward value like "20%", "₹50 off" or "Free chai".'.$extra,
                '{"suggestions":[{"title":"...","description":"...","type":"percentage","rewardValue":"20%"}]}',
            ],
            'push_message' => [
                "Write 4 push notifications {$shop} could send to its followers. Title max 40 characters, body max 110 characters, "
                .'friendly with one emoji at most.'.(filled($input['goal'] ?? null) ? ' Goal: '.$input['goal'].'.' : '').$extra,
                '{"suggestions":[{"title":"...","body":"..."}]}',
            ],
            'combo_ideas' => [
                "Suggest 3 combo deals for {$shop} using only items from its menu. Give each a catchy name, the items, "
                .'and a combo price about 10–15% below the items added up.'.$extra,
                '{"suggestions":[{"name":"...","items":["..."],"price":199,"why":"..."}]}',
            ],
            'sales_tips' => [
                "Give 5 practical, low-cost tips to grow sales this week at {$shop} Each with a short title and 1–2 sentences."
                .' Mention Listee tools where they fit (spin & win, combos, happy hours, push notifications, QR at the counter).'.$extra,
                '{"suggestions":[{"title":"...","body":"..."}]}',
            ],
            'review_reply' => [
                $this->need($input, 'review', 'Paste the review first.')
                    ."\nWrite 3 short, polite replies (max 45 words) from the owner of {$shop} to this "
                    .(filled($input['rating'] ?? null) ? $input['rating'].'-star ' : '').'review: "'.mb_substr((string) $input['review'], 0, 600).'"',
                '{"suggestions":[{"text":"..."}]}',
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

    /** @param  array<string, mixed>  $input */
    private function need(array $input, string $key, string $message): string
    {
        if (! filled($input[$key] ?? null)) {
            throw ValidationException::withMessages([$key => [$message]]);
        }

        return '';
    }

    /**
     * Where the monthly counts live: the limiter store (file in production,
     * where the default cache is `null` and would forget every count).
     */
    private function counter(): Repository
    {
        return Cache::store(config('cache.limiter') ?: 'file');
    }

    private function usageKey(Business $business): string
    {
        return 'ai-usage:'.$business->id.':'.now()->format('Y-m');
    }
}
