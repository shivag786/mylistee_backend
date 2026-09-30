<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every customer order on the platform, for the Super Admin.
 *
 * Unlike the owner's queue this shows orders still waiting on their payment and
 * ones withdrawn after a closed payment window: those are exactly what support
 * needs when a customer says they paid and the shop says nothing arrived.
 */
class OrderController extends Controller
{
    /** GET /admin/orders?status=&payment=&business=&search= */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with([
                'business:id,name,slug',
                'customer:id,name,phone,email',
                'items:id,order_id,quantity',
                'latestPayment',
            ])
            ->when($request->string('status')->trim()->value(), fn (Builder $q, $s) => $q->where('status', $s))
            ->when($request->string('payment')->trim()->value(), fn (Builder $q, $type) => $this->wherePaymentType($q, $type))
            ->when(
                $request->string('business')->trim()->value(),
                fn (Builder $q, $uuid) => $q->whereHas('business', fn ($b) => $b->where('uuid', $uuid)),
            )
            ->when($request->string('search')->trim()->value(), function (Builder $q, string $search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('token', $search)
                        ->orWhereHas('customer', fn ($c) => $c
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$search}%"))
                        // Paste a pay_… or order_… id straight from Razorpay.
                        ->orWhereHas('payments', fn ($p) => $p
                            ->where('gateway_payment_id', $search)
                            ->orWhere('gateway_order_id', $search));
                });
            })
            ->latest('id');

        $page = $query->paginate(min((int) $request->integer('perPage', 20), 100));

        return ApiResponse::success(
            AdminOrderResource::collection($page->getCollection()),
            'Orders retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                // Still waiting on a payment -- the rows support looks at first.
                'awaitingPayment' => Order::where('status', OrderStatus::AwaitingPayment->value)->count(),
            ],
        );
    }

    /** Mirrors Order::paymentType(), so a filter and a row can never disagree. */
    private function wherePaymentType(Builder $query, string $type): void
    {
        match ($type) {
            'cod' => $query->where(fn ($q) => $q
                ->whereNull('payment_choice')
                ->orWhere('payment_choice', '!=', 'online')
                ->orWhere('online_amount', '<=', 0)),
            'online' => $query->where('payment_choice', 'online')
                ->where('online_amount', '>', 0)
                ->whereColumn('online_amount', '>=', 'total'),
            'partial' => $query->where('payment_choice', 'online')
                ->where('online_amount', '>', 0)
                ->whereColumn('online_amount', '<', 'total'),
            default => null,
        };
    }
}
