<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
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

    /**
     * GET /business/orders?status=&since=&from=&to= — `from`/`to` are days
     * (Y-m-d) in the shop's timezone, both ends included.
     */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $zone = config('app.business_timezone', 'Asia/Kolkata');
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->value(), $zone)->startOfDay()->utc() : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->value(), $zone)->endOfDay()->utc() : null;

        $query = $business->orders()
            // Never an order still waiting on its online payment -- not through
            // any filter. `?since=` and `?status=` skip the active() default
            // below, and an unpaid order must not reach the shop by either road:
            // it is not an order yet, and the customer may never pay for it.
            ->where('status', '!=', OrderStatus::AwaitingPayment->value)
            ->with(['items', 'customer:id,name', 'diningTable:id,label'])
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->when(
                $request->string('since')->trim()->value(),
                fn ($q, $since) => $q->where('created_at', '>', $since),
            )
            ->when($from, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q, $to) => $q->where('created_at', '<=', $to))
            ->latest('id');

        // Default view = active orders (needs attention) unless a status is given.
        if (! $request->filled('status') && ! $request->filled('since')) {
            $query->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::active()));
        }

        // A chosen date range can hold a busy month of orders.
        $limit = $from !== null || $to !== null ? 500 : 100;

        return ApiResponse::success(OrderResource::collection($query->limit($limit)->get()), 'Orders retrieved.');
    }

    /**
     * GET /business/walk-ins?page=&perPage= — people served at the counter
     * without a Listee account, one row per mobile (or name, for older orders
     * taken without a mobile), busiest first.
     */
    public function walkIns(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:5', 'max:50'],
        ]);
        $full = (bool) $business->currentPlan()?->showsFullCustomerDetails();

        $paginator = $business->orders()
            ->toBase()
            ->where('source', 'counter')
            ->whereNull('customer_id')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where(fn ($q) => $q->whereNotNull('delivery_phone')->orWhereNotNull('customer_label'))
            ->selectRaw('COALESCE(delivery_phone, customer_label) as person')
            ->selectRaw('MAX(delivery_phone) as phone, MAX(customer_label) as name')
            ->selectRaw('COUNT(*) as orders, SUM(total) as spent, MAX(created_at) as last_order_at')
            ->groupByRaw('COALESCE(delivery_phone, customer_label)')
            ->orderByDesc('orders')
            ->orderByDesc('last_order_at')
            ->paginate($data['perPage'] ?? 10, page: $data['page'] ?? 1);

        return ApiResponse::success([
            'customers' => collect($paginator->items())->map(fn ($row) => [
                'name' => $row->name,
                'phone' => $full ? $row->phone : \App\Services\ShopPushService::maskPhone($row->phone),
                'orders' => (int) $row->orders,
                'spent' => round((float) $row->spent, 2),
                'lastOrderAt' => $row->last_order_at ? Carbon::parse($row->last_order_at)->toIso8601String() : null,
            ])->values()->all(),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ], 'Walk-in customers.');
    }

    /**
     * POST /business/orders/quote — what a counter cart costs for the customer
     * at the counter, with the shop's coupons they can use (found by mobile).
     */
    public function quote(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.type' => ['required', Rule::in(['product', 'combo'])],
            'items.*.id' => ['required', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'items.*.variant' => ['nullable', 'string', 'max:16'],
            'items.*.addons' => ['nullable', 'array', 'max:20'],
            'items.*.addons.*' => ['string', 'max:16'],
            'serviceType' => ['nullable', Rule::in(ServiceType::values())],
            'customerPhone' => ['nullable', 'string', 'max:20'],
            'couponCode' => ['nullable', 'string', 'max:40'],
        ]);

        return ApiResponse::success($this->orders->counterQuote(
            $business,
            $validated['items'],
            $validated['customerPhone'] ?? null,
            $validated['couponCode'] ?? null,
            isset($validated['serviceType']) ? ServiceType::from($validated['serviceType']) : null,
        ), 'Quote ready.');
    }

    /**
     * POST /business/orders — a walk-in order taken at the counter. Starts
     * accepted (and in the kitchen); `paid` records the payment straight away.
     */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.type' => ['required', Rule::in(['product', 'combo'])],
            'items.*.id' => ['required', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'items.*.variant' => ['nullable', 'string', 'max:16'],
            'items.*.addons' => ['nullable', 'array', 'max:20'],
            'items.*.addons.*' => ['string', 'max:16'],
            'serviceType' => ['nullable', Rule::in(ServiceType::values())],
            'table' => ['nullable', 'string'],
            'serviceAddress' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:300'],
            'customerName' => ['nullable', 'string', 'max:80'],
            'paid' => ['nullable', Rule::in(PaymentMethod::values())],
            'customerPhone' => ['nullable', 'string', 'regex:/^(\+?91)?[6-9]\d{9}$/'],
            'couponCode' => ['nullable', 'string', 'max:40'],
        ], [
            'customerPhone.regex' => 'Enter a valid 10-digit mobile number.',
        ]);

        $order = $this->orders->placeAtCounter(
            $business,
            $request->user(),
            $validated['items'],
            isset($validated['serviceType']) ? ServiceType::from($validated['serviceType']) : null,
            $validated['table'] ?? null,
            $validated['note'] ?? null,
            $validated['customerName'] ?? null,
            $validated['serviceAddress'] ?? null,
            isset($validated['paid']) ? PaymentMethod::from($validated['paid']) : null,
            $validated['customerPhone'] ?? null,
            $validated['couponCode'] ?? null,
        );

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'customer:id,name', 'diningTable:id,label'])),
            "Order #{$order->token} created.",
            status: 201,
        );
    }

    /** PATCH /business/orders/{uuid}/kitchen — queued / preparing / ready. */
    public function kitchen(Request $request, string $uuid): JsonResponse
    {
        $order = $this->business($request)->orders()->where('uuid', $uuid)->first();
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::enum(KitchenStatus::class)],
        ]);

        $order = $this->orders->setKitchenStatus($order, KitchenStatus::from($validated['status']));

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'customer:id,name', 'diningTable:id,label'])),
            $order->kitchen_status === KitchenStatus::Ready ? "Order #{$order->token} is ready." : 'Order updated.',
        );
    }

    /** PATCH /business/orders/{uuid}/status */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $order = $this->business($request)->orders()
            ->where('status', '!=', OrderStatus::AwaitingPayment->value)
            ->with(['items', 'diningTable:id,label'])->where('uuid', $uuid)->first();
        if ($order === null) {
            return ApiResponse::error('Order not found.', status: 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'paid', 'completed', 'cancelled'])],
            // Only meaningful when marking paid: how the customer paid at the counter.
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],
        ]);

        $paymentMethod = isset($validated['payment_method'])
            ? PaymentMethod::from($validated['payment_method'])
            : null;

        $order = $this->orders->transition($order, OrderStatus::from($validated['status']), $request->user(), $paymentMethod);

        return ApiResponse::success(
            new OrderResource($order->load(['items', 'customer:id,name', 'diningTable:id,label'])),
            'Order updated.',
        );
    }
}
