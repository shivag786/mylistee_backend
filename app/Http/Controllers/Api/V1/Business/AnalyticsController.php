<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business analytics dashboard data (document/phase/07 §Analytics, Milestone 12).
 * Scoped to the authenticated owner's business.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /** GET /business/analytics?days=30 */
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $days = (int) $request->integer('days', 30);

        return ApiResponse::success(
            $this->analytics->forBusiness($business, $days),
            'Analytics retrieved.',
        );
    }
}
