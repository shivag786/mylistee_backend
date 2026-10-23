<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RewardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's loyalty rewards as scratch cards: one per step they reached
 * at a shop (3rd order, 5th order…), hidden until they scratch it open.
 */
class RewardCardController extends Controller
{
    public function __construct(private readonly RewardService $rewards) {}

    /** GET /rewards/cards */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rewards->scratchCards($request->user()), 'Your reward cards.');
    }

    /** POST /rewards/cards/{uuid}/reveal */
    public function reveal(Request $request, string $uuid): JsonResponse
    {
        $card = $this->rewards->reveal($request->user(), $uuid);
        if ($card === null) {
            return ApiResponse::error('Reward card not found.', status: 404);
        }

        return ApiResponse::success($card, 'Card scratched.');
    }
}
