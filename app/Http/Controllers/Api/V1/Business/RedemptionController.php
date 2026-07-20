<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\RewardStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RedemptionResource;
use App\Services\RedemptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reward redemption for the business owner (document/phase/11 §Redemption).
 * Scoped to the owner's own business.
 */
class RedemptionController extends Controller
{
    public function __construct(private readonly RedemptionService $redemptions) {}

    /** POST /business/redeem/verify { code } — preview a code before redeeming. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $reward = $this->redemptions->verify($business, $data['code']);

        return ApiResponse::success(new RedemptionResource($reward), 'Reward is valid.');
    }

    /** POST /business/redeem { code } — mark a reward redeemed. */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $reward = $this->redemptions->redeem($business, $data['code'], $request->user());

        return ApiResponse::success(new RedemptionResource($reward), 'Reward redeemed successfully.');
    }

    /** GET /business/redemptions — recent redemptions for this business. */
    public function history(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $rewards = $business->rewards()
            ->where('status', RewardStatus::Redeemed->value)
            ->with('customer')
            ->latest('redeemed_at')
            ->limit(50)
            ->get();

        return ApiResponse::success(RedemptionResource::collection($rewards), 'Redemption history.');
    }
}
