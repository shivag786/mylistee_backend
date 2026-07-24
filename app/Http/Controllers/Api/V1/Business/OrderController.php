<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Owner order queue (Phase 7.5). The dashboard polls `index` for new orders
 * (optionally `?since=`) and rings; the owner confirms, marks paid, completes,
 * or cancels via `status`.
 */
class OrderController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly OrderService $orders) {}

    /** GET /business/orders?status=&since= */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $query = $business->orders()
            ->with(['items', 'customer:id,name'])
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->when(
                $request->string('since')->trim()->value(),
                fn ($q, $since) => $q->where('created_at', '>', $since),
            )
            ->latest('id');

        // Default view = active orders (needs attention) unless a status is given.
        if (! $request->filled('status') && ! $request->filled('since')) {
            $query->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::active()));
        }

        return ApiResponse::success(OrderResource::collection($query->limit(100)->get()), 'Orders retrieved.');
    }

    /** PATCH /business/orders/{uuid}/status */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $order = $this->business($request)->orders()->with('items')->where('uuid', $uuid)->first();
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'paid', 'completed', 'cancelled'])],
        ]);

        $order = $this->orders->transition($order, OrderStatus::from($validated['status']), $request->user());

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'customer:id,name'])),
            'Order updated.',
        );
    }
}
