<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Combo;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\FeedRanker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The top of the customer home page, in one call:
 *  - `hero`: dishes for the 3D carousel — deals, specials and bestsellers with a
 *    photo, from shops the viewer follows first, then nearby (two per shop);
 *  - `stories`: shop circles — followed shops, plus shops with something new
 *    today (a deal, a spin, a special, a new combo), each with a headline and a
 *    few dishes for the full-screen story.
 *
 * Optional `lat`/`lng`; a signed-in viewer's follows rank first (FeedRanker).
 */
class HomeSpotlightController extends Controller
{
    private const POOL = 150;

    private const HERO = 8;

    private const STORIES = 12;

    private const STORY_ITEMS = 4;

    /** GET /home/spotlight?lat=&lng= */
    public function index(Request $request): JsonResponse
    {
        $ranker = FeedRanker::fromRequest($request);

        $shops = Business::query()
            ->where('status', BusinessStatus::Active->value)
            ->withCount('liveOffers')
            ->with([
                'products' => fn ($q) => $q->where('is_visible', true)->where('in_stock', true)
                    ->whereNotNull('image_path')->with('promotions'),
                'combos' => fn ($q) => $q->where('is_visible', true)->latest('id'),
            ])
            ->latest('id')
            ->limit(self::POOL)
            ->get();

        return ApiResponse::success([
            'hero' => $this->hero($shops, $ranker),
            'stories' => $this->stories($shops, $ranker),
        ], 'Home spotlight.');
    }

    /**
     * @param  Collection<int, Business>  $shops
     * @return list<array<string, mixed>>
     */
    private function hero(Collection $shops, FeedRanker $ranker): array
    {
        $products = $shops->flatMap(fn (Business $b) => $b->products->each(fn (Product $p) => $p->setRelation('business', $b)));

        return $ranker->rank(
            $products,
            fn (Product $p) => $p->business,
            fn (Product $p) => $this->dishScore($p),
            self::HERO,
        )->map(fn (Product $p) => [
            ...$this->dish($p),
            'label' => $this->dishLabel($p),
            'business' => [
                'slug' => $p->business->slug,
                'name' => $p->business->name,
                'logoUrl' => $this->url($p->business->logo_path),
                'distanceMeters' => $p->getAttribute('feed_distance_meters'),
                'isFollowed' => (bool) $p->getAttribute('feed_is_followed'),
            ],
        ])->all();
    }

    /**
     * @param  Collection<int, Business>  $shops
     * @return list<array<string, mixed>>
     */
    private function stories(Collection $shops, FeedRanker $ranker): array
    {
        return $shops
            ->map(fn (Business $b) => ['shop' => $b, 'news' => $this->headline($b)])
            // A circle is earned by following the shop or by it having news today.
            ->filter(fn (array $row) => $row['news'] !== null || $ranker->isFollowed($row['shop']))
            ->sortByDesc(fn (array $row) => $ranker->shopScore($row['shop']) + ($row['news'] !== null ? 20 : 0))
            ->take(self::STORIES)
            ->map(function (array $row) use ($ranker) {
                /** @var Business $b */
                $b = $row['shop'];
                $items = $b->products
                    ->sortByDesc(fn (Product $p) => $this->dishScore($p))
                    ->take(self::STORY_ITEMS)
                    ->map(fn (Product $p) => [...$this->dish($p), 'label' => $this->dishLabel($p)])
                    ->values()
                    ->all();

                return [
                    'slug' => $b->slug,
                    'name' => $b->name,
                    'logoUrl' => $this->url($b->logo_path),
                    'coverUrl' => $this->url($b->cover_path),
                    'isFollowed' => $ranker->isFollowed($b),
                    'distanceMeters' => $ranker->distance($b),
                    'hasNews' => $row['news'] !== null,
                    'kind' => $row['news']['kind'] ?? null,
                    'headline' => $row['news']['text'] ?? null,
                    'items' => $items,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * What's new at a shop today, best first: a deal, a spin, a special, a combo.
     *
     * @return array{kind: string, text: string}|null
     */
    private function headline(Business $b): ?array
    {
        $best = $b->products
            ->map(fn (Product $p) => ['p' => $p, 'pct' => $this->discountPct($p)])
            ->filter(fn (array $row) => $row['pct'] >= 1)
            ->sortByDesc('pct')
            ->first();
        if ($best !== null) {
            return ['kind' => 'deal', 'text' => round($best['pct']).'% off on '.$best['p']->name];
        }

        if ($b->live_offers_count > 0) {
            return ['kind' => 'spin', 'text' => 'Spin & win today'];
        }

        $special = $b->products->first(fn (Product $p) => $p->is_todays_special);
        if ($special !== null) {
            return ['kind' => 'special', 'text' => "Today's special: {$special->name}"];
        }

        $combo = $b->combos->first(fn (Combo $c) => $c->created_at?->gt(now()->subDays(7)) && $c->isActiveNow());
        if ($combo !== null) {
            return ['kind' => 'combo', 'text' => "New combo: {$combo->name}"];
        }

        return null;
    }

    private function discountPct(Product $p): float
    {
        $selling = (float) $p->selling_price;

        return $selling > 0 ? (($selling - $p->effectivePrice()) / $selling) * 100 : 0;
    }

    private function dishScore(Product $p): float
    {
        return min($this->discountPct($p) * 0.8, 40)
            + ($p->is_todays_special ? 15 : 0)
            + ($p->is_bestseller ? 10 : 0)
            + ($p->is_recommended ? 5 : 0);
    }

    private function dishLabel(Product $p): ?string
    {
        $pct = $this->discountPct($p);

        return match (true) {
            $pct >= 1 => round($pct).'% off',
            $p->activeDisplayPromotion() !== null => $p->activeDisplayPromotion()->displayLabel(),
            (bool) $p->is_todays_special => "Today's special",
            (bool) $p->is_bestseller => 'Bestseller',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function dish(Product $p): array
    {
        $price = round($p->effectivePrice(), 2);
        $was = $price < (float) $p->selling_price
            ? (float) $p->selling_price
            : ($p->mrp !== null && (float) $p->mrp > $price ? (float) $p->mrp : null);

        return [
            'id' => $p->uuid,
            'name' => $p->name,
            'imageUrl' => $this->url($p->image_path),
            'price' => $price,
            'strikePrice' => $was,
        ];
    }

    private function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
