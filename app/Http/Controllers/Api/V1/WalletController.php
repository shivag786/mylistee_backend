<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RewardStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RewardResource;
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
    public function __construct(private readonly WalletService $wallet) {}

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
}
