<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComboFeedResource;
use App\Models\Combo;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public "Meal combos" feed for the customer home page: visible combos that are
 * active right now, across active shops, biggest saving first. Uses the Combo
 * model's own active/savings logic so it matches the shop profile.
 */
class ComboFeedController extends Controller
{
    /** GET /combos */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min($request->integer('limit', 12), 30));

        $combos = Combo::query()
            ->where('is_visible', true)
            ->whereHas('business', fn ($q) => $q->where('status', BusinessStatus::Active->value))
            ->with(['business', 'items.product'])
            ->latest('id')
            ->limit(120)
            ->get()
            // isActiveNow() also honours the auto-enable/disable schedule windows.
            ->filter(fn (Combo $combo) => $combo->isActiveNow())
            ->sortByDesc(fn (Combo $combo) => $combo->savings())
            ->take($limit)
            ->values();

        return ApiResponse::success(ComboFeedResource::collection($combos), 'Combos retrieved.');
    }
}
