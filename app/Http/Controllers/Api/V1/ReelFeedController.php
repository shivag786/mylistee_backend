<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShopReelResource;
use App\Models\Business;
use App\Models\ShopReel;
use App\Support\ApiResponse;
use App\Support\FeedRanker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public Shop Reels: the home "Reels near you" feed (followed shops, then
 * nearby, then fresh — two per shop), one shop's reels, and a view counter.
 */
class ReelFeedController extends Controller
{
    /** GET /reels?lat=&lng=&limit= */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min($request->integer('limit', 12), 30));

        $reels = ShopReel::query()
            ->where('is_visible', true)
            ->whereHas('business', fn ($q) => $q->where('status', BusinessStatus::Active->value))
            ->with(['business', 'product.promotions'])
            ->latest('id')
            ->limit(200)
            ->get();

        $ranked = FeedRanker::fromRequest($request)->rank(
            $reels,
            fn (ShopReel $reel) => $reel->business,
            function (ShopReel $reel): float {
                $age = $reel->created_at?->diffInDays(now()) ?? 99;

                return ($age <= 3 ? 15 : ($age <= 7 ? 8 : 0)) + ($reel->product_id ? 5 : 0);
            },
            $limit,
        );

        return ApiResponse::success(ShopReelResource::collection($ranked), 'Reels.');
    }

    /** GET /businesses/{slug}/reels — one shop's reels, newest first. */
    public function forBusiness(string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)
            ->where('status', BusinessStatus::Active->value)
            ->firstOrFail();

        $reels = $business->hasMany(ShopReel::class)->getQuery()
            ->where('is_visible', true)
            ->with(['business', 'product.promotions'])
            ->latest('id')
            ->limit(30)
            ->get();

        return ApiResponse::success(ShopReelResource::collection($reels), 'Reels.');
    }

    /** POST /reels/{uuid}/view — counted once per viewer per reel per hour (by address). */
    public function view(Request $request, string $uuid): JsonResponse
    {
        // The app cache is off (CACHE_STORE=null); the rate limiter keeps its
        // own store, so it's what remembers "already counted this hour".
        RateLimiter::attempt(
            'reel-view:'.$uuid.':'.$request->ip(),
            1,
            fn () => ShopReel::where('uuid', $uuid)->where('is_visible', true)->increment('views'),
            3600,
        );

        return ApiResponse::success(message: 'Counted.');
    }
}
