<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerTokenResource;
use App\Services\TokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's rotating wallet token (Phase 7.3). Shown in the wallet, the
 * floating chip, and the profile; the owner enters it at the counter.
 */
class WalletTokenController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    /** GET /wallet/token — the active token, minting a fresh one if expired. */
    public function show(Request $request): JsonResponse
    {
        $token = $this->tokens->currentFor($request->user());

        return ApiResponse::success(new CustomerTokenResource($token), 'Wallet token.');
    }

    /** POST /wallet/token/refresh — force a new token. */
    public function refresh(Request $request): JsonResponse
    {
        $token = $this->tokens->generate($request->user());

        return ApiResponse::success(new CustomerTokenResource($token), 'New wallet token.');
    }
}
