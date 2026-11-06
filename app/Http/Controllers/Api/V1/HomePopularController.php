<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\FeedRanker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "Most ordered near you" for the customer home: the dishes people order the
 * most (quantity across non-cancelled orders in the popular window), from
 * active shops, nearest first when the phone sends `lat`/`lng`, and at most
 * two dishes per shop so one busy shop can't fill the row.
 *
 * GET /home/popular-items?lat=&lng=&city=&limit=
 */
class HomePopularController extends Controller
{
    /** Candidates read from the order history before filtering and ranking. */
    private const POOL = 150;

    private const PER_SHOP = 2;

    /** Beyond this, a shop is "in another town" and drops behind every nearby one. */
    private const NEAR_METERS = 25_000;

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 12), 30));
        $city = trim((string) $request->query('city', ''));
        $ranker = FeedRanker::fromRequest($request);
        $since = now()->subDays((int) config('orders.popular.window_days', 90));

        $counts = OrderItem::query()
            ->select('product_id', DB::raw('SUM(quantity) as ordered'))
            ->whereNotNull('product_id')
            ->whereHas('order', fn ($o) => $o
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '>=', $since))
            ->groupBy('product_id')
            ->orderByDesc('ordered')
            ->limit(self::POOL)
            ->pluck('ordered', 'product_id');

        if ($counts->isEmpty()) {
            return ApiResponse::success([], 'Popular items.');
        }

        $products = Product::query()
            ->whereIn('id', $counts->keys())
            ->where('is_visible', true)
            ->where('in_stock', true)
            ->whereHas('business', function ($b) use ($city): void {
                $b->where('status', BusinessStatus::Active->value);
                // Same rule as discovery: shops with no city set yet still show.
                if ($city !== '') {
                    $b->where(fn ($q) => $q->where('city', $city)->orWhereNull('city'));
                }
            })
            ->with(['business', 'promotions'])
            ->get();

        $perShop = [];
        $items = $products
            ->map(function (Product $p) use ($counts, $ranker) {
                $distance = $ranker->distance($p->business);

                return [
                    'p' => $p,
                    'ordered' => (int) $counts[$p->id],
                    'distance' => $distance,
                    'far' => $distance !== null && $distance > self::NEAR_METERS,
                ];
            })
            // Nearby first, then the most ordered, then the closest.
            ->sortBy([
                fn ($a, $b) => $a['far'] <=> $b['far'],
                fn ($a, $b) => $b['ordered'] <=> $a['ordered'],
                fn ($a, $b) => ($a['distance'] ?? PHP_INT_MAX) <=> ($b['distance'] ?? PHP_INT_MAX),
            ])
            ->filter(function (array $row) use (&$perShop) {
                $id = $row['p']->business_id;
                $perShop[$id] = ($perShop[$id] ?? 0) + 1;

                return $perShop[$id] <= self::PER_SHOP;
            })
            ->take($limit)
            ->map(fn (array $row) => $this->present($row['p'], $row['ordered'], $row['distance']))
            ->values()
            ->all();

        return ApiResponse::success($items, 'Popular items.');
    }

    /** @return array<string, mixed> */
    private function present(Product $p, int $ordered, ?int $distance): array
    {
        $price = round($p->effectivePrice(), 2);
        $was = $price < (float) $p->selling_price
            ? (float) $p->selling_price
            : ($p->mrp !== null && (float) $p->mrp > $price ? (float) $p->mrp : null);

        return [
            'id' => $p->uuid,
            'name' => $p->name,
            'imageUrl' => $p->image_path ? Storage::disk('public')->url($p->image_path) : null,
            'price' => $price,
            'strikePrice' => $was,
            'foodType' => $p->food_type?->value,
            'orderCount' => $ordered,
            'label' => $ordered.' ordered',
            'business' => [
                'slug' => $p->business->slug,
                'name' => $p->business->name,
                'logoUrl' => $p->business->logo_path ? Storage::disk('public')->url($p->business->logo_path) : null,
                'distanceMeters' => $distance,
            ],
        ];
    }
}
