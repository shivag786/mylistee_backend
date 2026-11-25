<?php

namespace App\Services\AiAdvisor;

use App\Enums\PromotionStatus;
use App\Models\AiSuggestion;
use App\Models\Business;
use App\Services\AiAssistantService;
use App\Services\PlanAccessService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The "AI sales manager" bubble in the owner web panel and the vendor app.
 *
 * Costs NO AI tokens: the tips are worked out from the shop's own menu and
 * order numbers with plain rules, and the AI ideas it points to were already
 * made by the nightly run (ai:advisor-nightly). The AI is only called when
 * the owner taps a task or asks a question (AiAssistantService, counted).
 *
 * Every shop sees it — shops without the AI module get the tips and a
 * "unlock" prompt, so they learn what it can do.
 *
 * Nudges (the bubble speaking up by itself) are rationed on the server, so
 * several tabs or the app and the panel together still follow the same rules:
 * at most `nudges_per_day`, `nudge_gap_hours` apart, the same tip not again
 * for `repeat_after_days`, and nothing for `snooze_hours` after "Not now".
 */
class AiCoachService
{
    private const DAY_NAMES = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    public function __construct(
        private readonly AdvisorStatsService $stats,
        private readonly AiAssistantService $ai,
        private readonly PlanAccessService $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Business $business, bool $wantNudge): array
    {
        $tips = $this->tips($business);
        $inPlan = $this->access->allows($business, 'ai_assistant');
        $usage = $this->ai->usage($business);

        return [
            'available' => $this->ai->enabled(),
            'inPlan' => $inPlan,
            ...$usage,
            'left' => $usage['left'] ?? AiAssistantService::UNLIMITED,
            'resetsOn' => now()->startOfMonth()->addMonth()->toDateString(),
            'pendingIdeas' => $inPlan ? $this->pendingIdeas($business) : 0,
            'tips' => $tips,
            'nudge' => $wantNudge ? $this->takeNudge($business, $tips) : null,
            'tasks' => array_keys(AiAssistantService::KINDS),
        ];
    }

    /** The owner closed a nudge ("Not now") or opened it. */
    public function record(Business $business, string $action, ?string $tipId): void
    {
        $state = $this->state($business);
        if ($action === 'dismiss') {
            $state['snoozedUntil'] = now()->addHours((int) config('ai_advisor.coach.snooze_hours', 24))->timestamp;
        }
        if ($tipId !== null) {
            $state['seen'][$tipId] = now()->timestamp;
        }
        $this->save($business, $state);
    }

    /**
     * Rule-based tips, best first. Cached for an hour per shop: opening the
     * panel many times does not re-count the orders.
     *
     * @return list<array<string, mixed>>
     */
    public function tips(Business $business): array
    {
        return $this->counter()->remember('ai-coach:tips:'.$business->id, now()->addHour(), function () use ($business) {
            try {
                return $this->buildTips($business);
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function buildTips(Business $business): array
    {
        $products = $business->products()->where('is_visible', true);
        $menuSize = (clone $products)->count();
        $noPhoto = (clone $products)->whereNull('image_path')->doesntHave('images')->count();
        $noText = (clone $products)->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))->count();
        $sales = $this->stats->sales($business);
        $growth = $this->stats->growth($business);
        $window = (int) ($sales['windowDays'] ?? 30);
        $orders = (int) ($sales['orders'] ?? 0);
        $offersLive = $business->promotions()
            ->whereIn('status', [PromotionStatus::Running->value, PromotionStatus::Scheduled->value])->count()
            + $business->combos()->where('is_visible', true)->count();

        $tips = [];
        $add = function (string $id, int $score, string $title, string $text, array $action) use (&$tips) {
            $tips[] = compact('id', 'score', 'title', 'text', 'action');
        };

        if ($menuSize === 0) {
            $add('menu_empty', 100, 'Your menu is empty',
                'Customers can not order yet. Snap a photo of your printed menu and AI adds every dish for you in a minute.',
                ['type' => 'route', 'route' => 'menuScan', 'label' => 'Scan my menu']);
        } elseif ($menuSize < 12) {
            $add('menu_small', 80, "Only {$menuSize} items on your menu",
                'A fuller menu gives customers more reasons to order. Add the rest of your dishes, drinks and combos — scan the printed menu to do it fast.',
                ['type' => 'route', 'route' => 'menuScan', 'label' => 'Add more dishes']);
        }

        if ($orders === 0 && $menuSize > 0) {
            $add('no_orders', 90, "No orders in the last {$window} days",
                'Put your Listee QR on every table and the counter, and share your shop link on WhatsApp so customers can order.',
                ['type' => 'route', 'route' => 'qr', 'label' => 'Get my QR']);
        }

        $top = $sales['topItems'][0] ?? null;
        if ($top !== null && ($top['qty'] ?? 0) >= 5) {
            $add('top_item', 60, "{$top['name']} is your best seller",
                "Sold {$top['qty']} times in {$window} days. Pair it with a drink or side as a combo to raise every bill.",
                ['type' => 'ai', 'task' => 'combo_ideas', 'label' => 'Make combo ideas']);
        }

        $bottom = collect($sales['bottomItems'] ?? [])->firstWhere('qty', 0);
        if ($bottom !== null && $orders >= 20) {
            $add('slow_item', 45, "{$bottom['name']} has not sold",
                "No orders for it in {$window} days. A better photo and description, or a small offer, can wake it up.",
                ['type' => 'ai', 'task' => 'product_description', 'input' => ['name' => $bottom['name']], 'label' => 'Rewrite with AI']);
        }

        $days = collect($sales['byWeekday'] ?? [])->filter(fn ($d) => ($d['orders'] ?? 0) > 0);
        if ($orders >= 20 && $days->count() >= 5) {
            $slow = $days->sortBy('orders')->first();
            $best = $days->sortByDesc('orders')->first();
            $name = fn ($d) => self::DAY_NAMES[$d['day']] ?? ucfirst((string) $d['day']);
            if ($best['orders'] >= 2 * max(1, $slow['orders'])) {
                $add('slow_day', 55, $name($slow).' is your slowest day',
                    "{$slow['orders']} orders against {$best['orders']} on ".$name($best).'. A happy hour on that day can fill the gap.',
                    ['type' => 'ai', 'task' => 'offer_ideas', 'input' => ['note' => 'slow '.$name($slow).'s'], 'label' => 'Get offer ideas']);
            }
        }

        if ($offersLive === 0 && $menuSize > 0) {
            $add('no_offer', 50, 'No offer is running',
                'Shops with a live offer or combo give customers a reason to pick them today. Let AI suggest one that fits your menu.',
                ['type' => 'ai', 'task' => 'offer_ideas', 'label' => 'Suggest an offer']);
        }

        $lapsed = (int) ($growth['lapsedCustomers'] ?? 0);
        if ($lapsed >= 5) {
            $add('lapsed', 65, "{$lapsed} customers have not come back",
                'They ordered before but not in the last month. A friendly notification with a small offer brings many of them back.',
                ['type' => 'ai', 'task' => 'push_message', 'input' => ['goal' => 'win back customers who have not ordered for a month'], 'label' => 'Write the message']);
        }

        $low = (int) ($growth['rating']['lowLastWindow'] ?? 0);
        if ($low > 0) {
            $add('low_reviews', 70, $low === 1 ? '1 unhappy review' : "{$low} unhappy reviews",
                'A quick, polite reply shows other customers you care. AI can draft it for you.',
                ['type' => 'route', 'route' => 'reviews', 'label' => 'See reviews']);
        }

        if ($menuSize >= 5 && $noPhoto >= max(3, (int) ceil($menuSize * 0.4))) {
            $add('no_photos', 40, "{$noPhoto} dishes have no photo",
                'Dishes with a photo get picked far more often. Add photos of your best sellers first.',
                ['type' => 'route', 'route' => 'products', 'label' => 'Add photos']);
        }

        if ($menuSize >= 5 && $noText >= max(3, (int) ceil($menuSize * 0.5))) {
            $add('no_text', 35, "{$noText} dishes have no description",
                'A one-line description makes a dish easier to choose. AI writes them for you.',
                ['type' => 'ai', 'task' => 'product_description', 'label' => 'Write descriptions']);
        }

        usort($tips, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($tips, 0, 6);
    }

    /**
     * One tip to show by itself, or null when the rules say "not now".
     * Taking it counts against today's nudges.
     *
     * @param  list<array<string, mixed>>  $tips
     * @return array<string, mixed>|null
     */
    private function takeNudge(Business $business, array $tips): ?array
    {
        $cfg = config('ai_advisor.coach', []);
        $state = $this->state($business);
        $now = now()->timestamp;
        $today = now()->toDateString();

        if (($state['snoozedUntil'] ?? 0) > $now) {
            return null;
        }
        if (($state['day'] ?? null) !== $today) {
            $state['day'] = $today;
            $state['count'] = 0;
        }
        if ($state['count'] >= (int) ($cfg['nudges_per_day'] ?? 2)) {
            return null;
        }
        if ($now - ($state['lastAt'] ?? 0) < (int) ($cfg['nudge_gap_hours'] ?? 4) * 3600) {
            return null;
        }

        $repeatAfter = (int) ($cfg['repeat_after_days'] ?? 3) * 86400;
        $tip = collect($tips)->first(fn ($t) => $now - ($state['seen'][$t['id']] ?? 0) >= $repeatAfter);
        if ($tip === null) {
            return null;
        }

        $state['count']++;
        $state['lastAt'] = $now;
        $state['seen'][$tip['id']] = $now;
        $this->save($business, $state);

        return $tip;
    }

    private function pendingIdeas(Business $business): int
    {
        return AiSuggestion::query()->where('business_id', $business->id)->where('status', 'pending')->count();
    }

    /** @return array<string, mixed> */
    private function state(Business $business): array
    {
        $state = $this->counter()->get($this->stateKey($business));

        return is_array($state) ? $state + ['seen' => [], 'count' => 0] : ['seen' => [], 'count' => 0];
    }

    /** @param  array<string, mixed>  $state */
    private function save(Business $business, array $state): void
    {
        // Forget tips seen long ago so the list stays small.
        $state['seen'] = array_filter($state['seen'] ?? [], fn ($t) => $t > now()->subDays(30)->timestamp);
        $this->counter()->put($this->stateKey($business), $state, now()->addDays(30));
    }

    private function stateKey(Business $business): string
    {
        return 'ai-coach:state:'.$business->id;
    }

    /** The limiter store (file in production; the default cache there is `null`). */
    private function counter(): Repository
    {
        return Cache::store(config('cache.limiter') ?: 'file');
    }
}
