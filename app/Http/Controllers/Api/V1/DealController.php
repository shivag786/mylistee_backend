<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Enums\PromotionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DealResource;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\FeedRanker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Public "Today's Deals" feed for the customer home page: visible, in-stock
 * products across active shops that have a promotion running right now. Uses
 * the Product model's promotion resolution so effective prices are consistent
 * with the business profile and owner catalogue.
 *
 * Ranked per viewer (FeedRanker): followed shops, then nearby (optional
 * `lat`/`lng`), then the biggest % off — at most two deals per shop.
 */
class DealController extends Controller
{
    /** GET /deals */
    public function index(Request $request): JsonResponse
    {
        $now = Carbon::now();
        $limit = max(1, min($request->integer('limit', 12), 30));
        // Optional single promotion type (e.g. `festival`) for themed rows.
        $type = $request->filled('type') ? $request->string('type')->toString() : null;

        // Narrow to products whose promotion is in Running status and inside its
        // date window at the DB level; the daily-time window + priority pick is
        // then refined in PHP via activePromotion() (single source of truth).
        $candidates = Product::query()
            ->where('is_visible', true)
            ->where('in_stock', true)
            ->whereHas('business', fn ($q) => $q->where('status', BusinessStatus::Active->value))
            ->whereHas('promotions', function ($q) use ($now, $type) {
                $q->where('status', PromotionStatus::Running->value)
                    ->when($type, fn ($p) => $p->where('promotion_type', $type))
                    ->where(fn ($d) => $d->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn ($d) => $d->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
            })
            ->with(['business', 'category', 'promotions'])
            ->latest('id')
            ->limit(120)
            ->get();

        $live = $candidates
            // Any active promotion type qualifies (incl. BOGO / quantity discounts,
            // shown by label since they don't change the unit price). When a `type`
            // is requested, the active promotion must be of that type.
            ->filter(function (Product $product) use ($type) {
                $promotion = $product->activeDisplayPromotion();

                return $promotion !== null
                    && ($type === null || $promotion->promotion_type->value === $type);
            })
            ->values();

        // Followed shops, then nearby, then the biggest % off — two deals per
        // shop at most (FeedRanker). Label-only promotions (BOGO etc.) still
        // place, on shop score alone.
        $deals = FeedRanker::fromRequest($request)->rank(
            $live,
            fn (Product $product) => $product->business,
            function (Product $product): float {
                $selling = (float) $product->selling_price;
                $pct = $selling > 0 ? (($selling - $product->effectivePrice()) / $selling) * 100 : 0;

                return min($pct * 0.8, 40);
            },
            $limit,
        );

        return ApiResponse::success(DealResource::collection($deals), 'Deals retrieved.');
    }
}
