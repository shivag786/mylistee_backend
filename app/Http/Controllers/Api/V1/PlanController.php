<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public list of subscription plans for the pricing / upgrade screen
 * (document/phase/02 §Subscriptions). Read-only; admin management is Milestone 14.
 */
class PlanController extends Controller
{
    /** Cache key for the public plans list — forgotten when an admin edits a plan. */
    public const CACHE_KEY = 'plans.public';

    /** GET /plans */
    public function index(): JsonResponse
    {
        $plans = Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => Plan::query()->public()->get());

        return ApiResponse::success(PlanResource::collection($plans), 'Plans retrieved.');
    }
}
