<?php

namespace App\Services;

use App\Models\Business;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The shop owner's AI helper (vendor app → AI assistant): writes dish
 * descriptions, offer and combo ideas, notification copy, sales tips and
 * review replies, through OpenAI's Chat Completions API.
 *
 * Everything comes back as a short list of suggestions the owner taps to use;
 * nothing is saved or sent by itself.
 *
 * Admin → Settings → AI assistant holds the switch, the OpenAI key, the model
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

    public function enabled(): bool
    {
        return (bool) $this->settings->get('aiEnabled', false) && filled($this->settings->get('openaiApiKey'));
    }

    public function monthlyLimit(): int
    {
        return max(0, (int) $this->settings->get('aiMonthlyLimit', 100));
    }

    public function usedThisMonth(Business $business): int
    {
        return (int) Cache::get($this->usageKey($business), 0);
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

        if ($response->failed()) {
            Log::warning('OpenAI request failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            throw new RuntimeException($response->status() === 401
                ? 'The AI key set by Listee is not working. Please tell Listee support.'
                : 'The AI is busy right now. Please try again in a minute.');
        }

        $json = json_decode((string) $response->json('choices.0.message.content'), true);
        $items = is_array($json) ? ($json['suggestions'] ?? null) : null;
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('The AI did not return suggestions. Please try again.');
        }

        Cache::put($this->usageKey($business), $this->usedThisMonth($business) + 1, now()->endOfMonth()->addDay());

        return array_values(array_filter($items, 'is_array'));
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

    private function usageKey(Business $business): string
    {
        return 'ai-usage:'.$business->id.':'.now()->format('Y-m');
    }
}
