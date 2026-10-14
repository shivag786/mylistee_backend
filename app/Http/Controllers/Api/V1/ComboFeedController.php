<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComboFeedResource;
use App\Models\Combo;
use App\Support\ApiResponse;
use App\Support\FeedRanker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public "Meal combos" feed for the customer home page: visible combos that are
 * active right now, across active shops. Uses the Combo model's own active
 * logic so it matches the shop profile.
 *
 * Ranked per viewer (FeedRanker): followed shops, then nearby ones, then the
 * bigger saving — at most two combos per shop so one shop can't fill the row.
 * Optional `lat`/`lng` query params give the distance.
 */
class ComboFeedController extends Controller
{
    /** GET /combos?lat=&lng=&limit= */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min($request->integer('limit', 12), 30));
        $ranker = FeedRanker::fromRequest($request);

        $active = Combo::query()
            ->where('is_visible', true)
            ->whereHas('business', fn ($q) => $q->where('status', BusinessStatus::Active->value))
            ->with(['business', 'items.product'])
            ->latest('id')
            ->limit(200)
            ->get()
            // isActiveNow() also honours the auto-enable/disable schedule windows.
            ->filter(fn (Combo $combo) => $combo->isActiveNow())
            ->values();

        $combos = $ranker->rank(
            $active,
            fn (Combo $combo) => $combo->business,
            function (Combo $combo): float {
                $total = $combo->totalPrice();
                $savingPct = $total > 0 ? ($combo->savings() / $total) * 100 : 0;
                $fresh = $combo->created_at?->gt(now()->subDays(7)) ? 5 : 0;
                // Combos about to end move up — that's when they need eyes.
                $endingSoon = $combo->auto_disable && $combo->ends_at?->between(now(), now()->addDay()) ? 10 : 0;

                return min($savingPct * 0.8, 30) + $fresh + $endingSoon;
            },
            $limit,
        );

        return ApiResponse::success(ComboFeedResource::collection($combos), 'Combos retrieved.');
    }
}
