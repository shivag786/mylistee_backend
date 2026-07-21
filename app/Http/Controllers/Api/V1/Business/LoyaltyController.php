<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyRewardResource;
use App\Models\LoyaltyReward;
use App\Services\LoyaltyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loyalty (Listee Coins) configuration for the business owner (Phase 2): earn
 * rates + reward tiers. Every action is scoped to the owner's own business.
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /** GET /business/loyalty — program settings + reward tiers. */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        return ApiResponse::success([
            'program' => $this->loyalty->describeProgram($business),
            'rewards' => LoyaltyRewardResource::collection($business->loyaltyRewards),
        ], 'Loyalty settings retrieved.');
    }

    /** PUT /business/loyalty — update earn rates + toggle. */
    public function update(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'coinsPerSpin' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'coinsPerFirstScan' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'coinsPerCheckin' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'coinsPerReview' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'coinsPerRedeem' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'monthlyBudgetCap' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $this->loyalty->updateProgram($business, [
            'enabled' => $validated['enabled'],
            'coins_per_spin' => $validated['coinsPerSpin'] ?? null,
            'coins_per_first_scan' => $validated['coinsPerFirstScan'] ?? null,
            'coins_per_checkin' => $validated['coinsPerCheckin'] ?? null,
            'coins_per_review' => $validated['coinsPerReview'] ?? null,
            'coins_per_redeem' => $validated['coinsPerRedeem'] ?? null,
            'monthly_budget_cap' => $validated['monthlyBudgetCap'] ?? null,
        ]);

        return ApiResponse::success(
            $this->loyalty->describeProgram($business->fresh()),
            'Loyalty settings saved.',
        );
    }

    /** POST /business/loyalty/rewards — create a reward tier. */
    public function storeReward(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $reward = $this->loyalty->createReward($business, $this->rewardData($request));

        return ApiResponse::success(new LoyaltyRewardResource($reward), 'Reward tier created.', status: 201);
    }

    /** PUT /business/loyalty/rewards/{uuid} — update a reward tier. */
    public function updateReward(Request $request, string $uuid): JsonResponse
    {
        $reward = $this->resolveOwnedReward($request, $uuid);
        if ($reward === null) {
            return ApiResponse::error('Reward tier not found.', status: 404);
        }

        $reward = $this->loyalty->updateReward($reward, $this->rewardData($request));

        return ApiResponse::success(new LoyaltyRewardResource($reward), 'Reward tier updated.');
    }

    /** DELETE /business/loyalty/rewards/{uuid} — remove a reward tier. */
    public function destroyReward(Request $request, string $uuid): JsonResponse
    {
        $reward = $this->resolveOwnedReward($request, $uuid);
        if ($reward === null) {
            return ApiResponse::error('Reward tier not found.', status: 404);
        }

        $this->loyalty->deleteReward($reward);

        return ApiResponse::success(message: 'Reward tier deleted.');
    }

    /** @return array<string, mixed> */
    private function rewardData(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'coinsCost' => ['required', 'integer', 'min:1', 'max:1000000'],
            'rewardValue' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'coins_cost' => $validated['coinsCost'],
            'reward_value' => $validated['rewardValue'] ?? null,
            'active' => $validated['active'] ?? true,
            'stock' => $validated['stock'] ?? null,
            'sort_order' => $validated['sortOrder'] ?? 0,
        ];
    }

    private function resolveOwnedReward(Request $request, string $uuid): ?LoyaltyReward
    {
        $business = $request->user()->business();

        return $business?->loyaltyRewards()->where('uuid', $uuid)->first();
    }
}
