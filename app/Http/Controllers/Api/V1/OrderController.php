<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Business;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $user = $request->user();
        $orders = $user->orders()
            ->with(['items', 'business:id,name,slug', 'diningTable:id,label'])
            ->latest('id')
            ->limit(50)
            ->get();

        $this->flagReviewed($orders, $user);

        return ApiResponse::success(OrderResource::collection($orders), 'Your orders.');
    }

    /** GET /orders/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $order = $user->orders()->with(['items', 'business:id,name,slug', 'diningTable:id,label'])->where('uuid', $uuid)->first();
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        $this->flagReviewed(collect([$order]), $user);

        return ApiResponse::success(new OrderResource($order), 'Order retrieved.');
    }

    /**
     * Tag each order with `already_reviewed` (has the customer reviewed that
     * shop) in one query, so the review nudge never bothers a repeat reviewer.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function flagReviewed(Collection $orders, User $user): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $reviewedBusinessIds = Review::where('customer_id', $user->id)
            ->whereIn('business_id', $orders->pluck('business_id')->unique())
            ->pluck('business_id')
            ->all();

        $orders->each(fn ($order) => $order->setAttribute(
            'already_reviewed',
            in_array($order->business_id, $reviewedBusinessIds, true),
        ));
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
            'serviceType' => ['nullable', Rule::in(ServiceType::values())],
            'table' => ['nullable', 'string'],
            'serviceAddress' => ['nullable', 'string', 'max:500'],
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
            isset($validated['serviceType']) ? ServiceType::from($validated['serviceType']) : null,
            $validated['table'] ?? null,
            $validated['serviceAddress'] ?? null,
        );

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'business:id,name,slug', 'diningTable:id,label'])),
            'Order placed.',
            status: 201,
        );
    }
}
