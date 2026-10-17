<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner Settings → shop availability: close the shop for a while (it reopens
 * by itself), reopen early, or pause new orders while staying open.
 */
class AvailabilityController extends Controller
{
    /** POST /business/close { hours: 1..720, note? } */
    public function close(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $v = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:720'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $business->forceFill([
            'closed_until' => now()->addHours((int) $v['hours']),
            'closed_note' => filled($v['note'] ?? null) ? trim($v['note']) : null,
        ])->save();

        $until = $business->closed_until->timezone(config('app.business_timezone'))->format('d M, h:i A');

        return ApiResponse::success($this->present($business), "Shop closed until {$until}. It reopens by itself.");
    }

    /** POST /business/reopen */
    public function reopen(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $business->forceFill(['closed_until' => null, 'closed_note' => null])->save();

        return ApiResponse::success($this->present($business), 'Shop is open again.');
    }

    /** PUT /business/orders-paused { paused: bool } */
    public function pauseOrders(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $v = $request->validate(['paused' => ['required', 'boolean']]);
        $business->forceFill(['orders_paused' => (bool) $v['paused']])->save();

        return ApiResponse::success(
            $this->present($business),
            $business->orders_paused ? 'New orders paused.' : 'Taking orders again.',
        );
    }

    private function present($business): BusinessResource
    {
        return new BusinessResource($business->load(['category', 'gallery', 'qrCode']));
    }
}
