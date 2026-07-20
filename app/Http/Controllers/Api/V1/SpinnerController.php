<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOfferResource;
use App\Http\Resources\RewardResource;
use App\Models\Business;
use App\Services\SpinnerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The spin action (document/phase/11 POST /spinner/spin). Authenticated
 * customers only; the backend decides the winning reward.
 */
class SpinnerController extends Controller
{
    public function __construct(private readonly SpinnerService $spinner) {}

    /** POST /spinner/spin { businessSlug } */
    public function spin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'businessSlug' => ['required', 'string', Rule::exists('businesses', 'slug')],
        ]);

        $business = Business::where('slug', $validated['businessSlug'])->firstOrFail();

        $result = $this->spinner->spin($request->user(), $business, [
            'ip' => $request->ip(),
            'device' => $request->userAgent(),
        ]);

        return ApiResponse::success([
            'reward' => new RewardResource($result['reward']->load('business')),
            'offer' => new PublicOfferResource($result['offer']),
        ], 'Congratulations! You won a reward.');
    }
}
