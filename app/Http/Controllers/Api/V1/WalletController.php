<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RewardStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RewardResource;
use App\Http\Resources\WalletTransactionResource;
use App\Services\LoyaltyService;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer wallet (document/phase/11 §Wallet Endpoints). All actions are scoped
 * to the authenticated customer's own rewards.
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly LoyaltyService $loyalty,
    ) {}

    /** GET /wallet — summary counts. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: ['summary' => $this->wallet->summary($request->user())],
            message: 'Wallet summary.',
        );
    }

    /** GET /wallet/rewards?status=active|redeemed|expired */
    public function rewards(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(RewardStatus::class)],
        ]);

        $status = isset($validated['status']) ? RewardStatus::from($validated['status']) : null;

        return ApiResponse::success(
            data: RewardResource::collection($this->wallet->rewards($request->user(), $status)),
            message: 'Wallet rewards.',
        );
    }

    /** GET /wallet/coins — Listee Coins balance + per-business breakdown. */
    public function coins(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->loyalty->coinSummary($request->user()),
            message: 'Coin balance.',
        );
    }

    /** GET /wallet/coins/transactions — recent coin ledger entries. */
    public function coinTransactions(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: WalletTransactionResource::collection($this->loyalty->transactions($request->user())),
            message: 'Coin history.',
        );
    }
}
