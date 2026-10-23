<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
use App\Services\OrderAlertService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lets an owner check that new-order alerts reach their phone. */
class OrderAlertController extends Controller
{
    public function __construct(
        private readonly OrderAlertService $alerts,
        private readonly FirebaseService $firebase,
    ) {}

    /** POST /business/order-alerts/test */
    public function test(Request $request): JsonResponse
    {
        $owner = $request->user();
        $devices = $owner->deviceTokens()->count();

        if (! $this->firebase->isConfigured()) {
            return ApiResponse::error('Push notifications are not set up on the server yet.', status: 503);
        }
        if ($devices === 0) {
            return ApiResponse::error('Turn on order alerts on this phone first.', status: 422);
        }

        $this->alerts->test($owner);

        return ApiResponse::success(['devices' => $devices], 'Test alert sent.');
    }
}
