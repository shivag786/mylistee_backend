<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Str;

/**
 * Suggests offers an owner could run (Phase 2). Two deterministic sources —
 * category templates and analytics-driven nudges — that always work offline;
 * an optional Claude AI layer (AiOfferSuggestionService) is merged on top when
 * an API key is configured.
 *
 * A suggestion: ['title', 'type', 'rewardValue', 'reason', 'source'].
 */
class OfferSuggestionService
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AiOfferSuggestionService $ai,
    ) {}

    /**
     * All suggestions for a business: templates + analytics nudges (+ AI when
     * configured), de-duplicated by title.
     *
     * @return array{suggestions: array<int, array<string, mixed>>, aiEnabled: bool}
     */
    public function forBusiness(Business $business): array
    {
        $suggestions = array_merge(
            $this->analyticsNudges($business),
            $this->templates($business),
        );

        if ($this->ai->isConfigured()) {
            $suggestions = array_merge($this->ai->suggest($business), $suggestions);
        }

        return [
            'suggestions' => $this->dedupe($suggestions),
            'aiEnabled' => $this->ai->isConfigured(),
        ];
    }

    /**
     * Category-appropriate starter offers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templates(Business $business): array
    {
        $key = $this->categoryKey($business);

        $byCategory = [
            'food' => [
                ['title' => 'Happy Hour 20% Off', 'type' => 'percentage', 'rewardValue' => '20% off', 'reason' => 'Fill quiet afternoons (4–6pm) with a limited-time discount.'],
                ['title' => 'Buy One Get One Free', 'type' => 'bogo', 'rewardValue' => 'BOGO', 'reason' => 'A classic footfall driver for cafés and restaurants.'],
                ['title' => 'Free Dessert with Meal', 'type' => 'free_item', 'rewardValue' => 'Free dessert', 'reason' => 'Low-cost sweetener that lifts average spend.'],
            ],
            'beauty' => [
                ['title' => '15% Off First Visit', 'type' => 'percentage', 'rewardValue' => '15% off', 'reason' => 'Convert first-time walk-ins into regulars.'],
                ['title' => 'Free Add-on Service', 'type' => 'free_item', 'rewardValue' => 'Free add-on', 'reason' => 'Showcase a premium service at no risk to the customer.'],
            ],
            'retail' => [
                ['title' => 'Flat ₹100 Off ₹500+', 'type' => 'flat', 'rewardValue' => '₹100 off', 'reason' => 'Nudge basket size above a threshold.'],
                ['title' => 'Weekend Combo Deal', 'type' => 'combo', 'rewardValue' => 'Combo', 'reason' => 'Bundle slow-moving stock with a bestseller.'],
            ],
            'fitness' => [
                ['title' => '7-Day Free Trial', 'type' => 'free_item', 'rewardValue' => 'Free trial', 'reason' => 'Let prospects experience the space before committing.'],
                ['title' => '20% Off Annual Plan', 'type' => 'percentage', 'rewardValue' => '20% off', 'reason' => 'Reward long-term commitment and improve retention.'],
            ],
        ];

        $defaults = [
            ['title' => '10% Off Today', 'type' => 'percentage', 'rewardValue' => '10% off', 'reason' => 'A simple, universally appealing spin reward.'],
            ['title' => 'Mystery Reward', 'type' => 'mystery', 'rewardValue' => 'Surprise', 'reason' => 'Adds excitement to the spinner and boosts repeat spins.'],
        ];

        return $this->tag($byCategory[$key] ?? $defaults, 'template');
    }

    /**
     * Nudges derived from the last 30 days of analytics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function analyticsNudges(Business $business): array
    {
        $data = $this->analytics->forBusiness($business, 30);
        $summary = $data['summary'];
        $out = [];

        // No live offers → the spinner has nothing to give away.
        if (! $business->offers()->live()->exists()) {
            $out[] = [
                'title' => 'Launch your first reward',
                'type' => 'percentage',
                'rewardValue' => '10% off',
                'reason' => 'You have no live offers, so the spinner has nothing to award. Add one to start engaging customers.',
            ];
        }

        // Plenty of spins but few redemptions → the reward isn't compelling enough.
        if ($summary['rewards']['value'] >= 5 && $summary['redemptionRate'] < 30.0) {
            $out[] = [
                'title' => 'Sweeten your reward',
                'type' => 'bogo',
                'rewardValue' => 'BOGO',
                'reason' => "Only {$summary['redemptionRate']}% of won rewards were redeemed — a stronger offer like BOGO can lift redemptions.",
            ];
        }

        // Traffic but low spin conversion → make spinning more enticing.
        if ($summary['visits']['value'] >= 10 && $summary['spinConversionRate'] < 40.0) {
            $out[] = [
                'title' => 'Add a weekday-only deal',
                'type' => 'flat',
                'rewardValue' => '₹50 off',
                'reason' => "You get visits but only {$summary['spinConversionRate']}% spin — a time-boxed weekday deal gives them a reason to.",
            ];
        }

        return $this->tag($out, 'analytics');
    }

    /** Coarse category bucket used to pick templates. */
    private function categoryKey(Business $business): string
    {
        $name = Str::lower((string) $business->category?->name);

        return match (true) {
            Str::contains($name, ['food', 'cafe', 'café', 'restaurant', 'bakery', 'coffee', 'dining']) => 'food',
            Str::contains($name, ['beauty', 'salon', 'spa', 'wellness', 'grooming']) => 'beauty',
            Str::contains($name, ['retail', 'shop', 'store', 'fashion', 'grocery']) => 'retail',
            Str::contains($name, ['fitness', 'gym', 'yoga', 'sport']) => 'fitness',
            default => 'default',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function tag(array $items, string $source): array
    {
        return array_map(fn ($s) => $s + ['source' => $source], $items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function dedupe(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = Str::lower($item['title'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }
}
