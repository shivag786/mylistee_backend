<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Business;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer orders (Phase 7.5). A signed-in customer confirms a one-shop cart
 * into an order and can review their order history.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** GET /orders — the customer's orders, newest first. */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->with(['items', 'business:id,name,slug'])
            ->latest('id')
            ->limit(50)
            ->get();

        return ApiResponse::success(OrderResource::collection($orders), 'Your orders.');
    }

    /** GET /orders/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $order = $request->user()->orders()->with(['items', 'business:id,name,slug'])->where('uuid', $uuid)->first();
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        return ApiResponse::success(new OrderResource($order), 'Order retrieved.');
    }

    /** POST /orders */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['product', 'combo'])],
            'items.*.id' => ['required', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'coinsToUse' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $business = Business::where('slug', $validated['business'])
            ->where('status', BusinessStatus::Active->value)
            ->first();
        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        $order = $this->orders->place(
            $business,
            $request->user(),
            $validated['items'],
            (int) ($validated['coinsToUse'] ?? 0),
            $validated['note'] ?? null,
        );

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'business:id,name,slug'])),
            'Order placed.',
            status: 201,
        );
    }
}
