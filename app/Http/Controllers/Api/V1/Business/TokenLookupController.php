<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Services\LoyaltyService;
use App\Services\TokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Counter-side lookup of a customer's wallet token (Phase 7.3). The owner enters
 * the token the customer shows; we return who they are and their coin balance at
 * this business, so coins can be applied — without exposing a phone number.
 */
class TokenLookupController extends Controller
{
    use ResolvesBusiness;

    public function __construct(
        private readonly TokenService $tokens,
        private readonly LoyaltyService $loyalty,
    ) {}

    /** POST /business/token/lookup */
    public function lookup(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:8'],
        ]);

        $customer = $this->tokens->resolve($validated['token']);

        if ($customer === null || $customer->role !== UserRole::Customer) {
            return ApiResponse::error('No active customer found for this token.', status: 404);
        }

        return ApiResponse::success([
            'customer' => [
                'name' => $customer->name,
                'coinBalance' => $this->loyalty->balanceForBusiness($customer, $business),
            ],
        ], 'Customer found.');
    }
}
