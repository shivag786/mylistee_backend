<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyRewardResource;
use App\Http\Resources\RewardResource;
use App\Models\Business;
use App\Models\LoyaltyReward;
use App\Services\LoyaltyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing loyalty (Listee Coins) — view a business's reward tiers and
 * spend coins to redeem one (Phase 2, slice 4). Redeeming mints a normal reward
 * code the owner scans through the existing redemption flow.
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /** GET /businesses/{slug}/loyalty — tiers + this customer's balance here. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)->first();
        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        $customer = $request->user();

        return ApiResponse::success([
            'enabled' => $this->loyalty->isEnabled($business),
            'rewards' => LoyaltyRewardResource::collection($this->loyalty->availableRewards($business)),
            'coinBalance' => $customer ? $this->loyalty->balanceFor($customer) : 0,
            'businessBalance' => $customer ? $this->loyalty->balanceForBusiness($customer, $business) : 0,
        ], 'Loyalty rewards.');
    }

    /** POST /loyalty/redeem — spend coins on a reward tier. */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rewardId' => ['required', 'string'],
        ]);

        $tier = LoyaltyReward::where('uuid', $validated['rewardId'])->first();
        if ($tier === null) {
            return ApiResponse::error('Reward tier not found.', status: 404);
        }

        $reward = $this->loyalty->redeemTier($request->user(), $tier);

        return ApiResponse::success([
            'reward' => new RewardResource($reward),
            'coinBalance' => $this->loyalty->balanceFor($request->user()),
        ], 'Reward redeemed! Show the code to the business.', status: 201);
    }
}
