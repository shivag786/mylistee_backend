<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paying for an order online, from the customer's side.
 *
 * Kept apart from placing the order so POST /orders keeps its shape for older
 * clients, and so a failed payment can be retried against the same order:
 * each call here opens a fresh Razorpay order for whatever is still owed.
 *
 * Every endpoint is scoped to the signed-in customer's own orders.
 */
class OrderPaymentController extends Controller
{
    public function __construct(private readonly OrderPaymentService $payments) {}

    /** POST /orders/{uuid}/payment -- open Checkout for the online share. */
    public function create(Request $request, string $uuid): JsonResponse
    {
        $order = $this->ownOrder($request, $uuid);
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        if ($order->status !== OrderStatus::AwaitingPayment) {
            return ApiResponse::error('This order has nothing left to pay online.', status: 422);
        }

        try {
            $session = $this->payments->checkout($order);
        } catch (Throwable $e) {
            Log::error('Could not open Razorpay checkout for an order', [
                'orderId' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('We could not start the payment. Please try again.', status: 502);
        }

        return ApiResponse::success($session, 'Checkout ready.');
    }

    /** POST /orders/{uuid}/payment/verify -- settle Checkout's signed response. */
    public function verify(Request $request, string $uuid): JsonResponse
    {
        $order = $this->ownOrder($request, $uuid);
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        $validated = $request->validate([
            'razorpayOrderId' => ['required', 'string', 'max:64'],
            'razorpayPaymentId' => ['required', 'string', 'max:64'],
            'razorpaySignature' => ['required', 'string', 'max:256'],
        ]);

        $order = $this->payments->verify(
            $order,
            $validated['razorpayOrderId'],
            $validated['razorpayPaymentId'],
            $validated['razorpaySignature'],
        );

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'business:id,name,slug', 'diningTable:id,label'])),
            'Payment received.',
        );
    }

    /**
     * POST /orders/{uuid}/payment/release -- the customer closed the window.
     *
     * Withdraws the unpaid order and returns its coins. Safe to call more than
     * once, and a no-op once the payment has landed.
     */
    public function release(Request $request, string $uuid): JsonResponse
    {
        $order = $this->ownOrder($request, $uuid);
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        $order = $this->payments->release($order);

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'business:id,name,slug', 'diningTable:id,label'])),
            'Order withdrawn.',
        );
    }

    private function ownOrder(Request $request, string $uuid): ?Order
    {
        return $request->user()->orders()->where('uuid', $uuid)->first();
    }
}
