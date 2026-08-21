<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public list of subscription plans for the pricing / upgrade screen
 * (document/phase/02 §Subscriptions). Read-only; admin management is Milestone 14.
 */
class PlanController extends Controller
{
    /**
     * GET /plans
     *
     * Read straight from the database. This was cached for six hours, which
     * meant a price edited outside the admin panel — a direct SQL change, say —
     * kept showing the old figure. Prices are the one thing that must never be
     * stale, and the query is a handful of rows.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            PlanResource::collection(Plan::query()->public()->get()),
            'Plans retrieved.',
        );
    }
}
