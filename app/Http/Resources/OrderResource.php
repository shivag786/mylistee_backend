<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof OrderStatus ? $this->status->value : $this->status;
        $serviceType = $this->service_type instanceof ServiceType ? $this->service_type : ServiceType::tryFrom((string) $this->service_type);
        $serviceType ??= ServiceType::default();

        $paymentMethod = $this->payment_method instanceof PaymentMethod
            ? $this->payment_method
            : PaymentMethod::tryFrom((string) $this->payment_method);

        return [
            'id' => $this->uuid,
            'token' => $this->token,
            'status' => $status,
            'serviceType' => $serviceType->value,
            'serviceLabel' => $this->serviceLabel(),
            'paymentMethod' => $paymentMethod?->value,
            'paymentLabel' => $paymentMethod?->label(),
            'tableLabel' => $this->diningTable?->label,
            'serviceAddress' => $this->service_address,
            'deliveryPhone' => $this->delivery_phone,
            'deliveryLocation' => $this->delivery_lat !== null && $this->delivery_lng !== null
                ? ['lat' => (float) $this->delivery_lat, 'lng' => (float) $this->delivery_lng]
                : null,
            'subtotal' => (float) $this->subtotal,
            'coinsUsed' => (int) $this->coins_used,
            'coinDiscount' => (float) $this->coin_discount,
            // The shop's automatic bill discount and the coupon applied.
            'billDiscount' => (float) $this->bill_discount,
            'couponDiscount' => (float) $this->coupon_discount,
            'couponCode' => $this->coupon_id ? $this->coupon()->withTrashed()->value('code') : null,
            'deliveryFee' => (float) $this->delivery_fee,
            // Listee's fee and what the shop keeps — for the shop's eyes only.
            $this->mergeWhen($request->routeIs('business.*'), fn () => [
                'platformFee' => (float) $this->platform_fee,
                'gatewayFee' => (float) $this->gateway_fee,
                'vendorEarning' => $this->vendor_earning !== null ? (float) $this->vendor_earning : null,
            ]),
            'total' => (float) $this->total,
            // How the bill splits. `paymentChoice` is null on orders from before
            // online payment existed, which were all cash at the counter.
            'paymentChoice' => $this->payment_choice,
            'onlineAmount' => (float) $this->online_amount,
            'convenienceFee' => (float) $this->convenience_fee,
            'paidOnline' => $this->isPaidOnline(),
            // What the shop still collects in person. The convenience fee is never
            // in it: that paid for the gateway, not the order.
            'amountDue' => $this->amountDue(),
            'coinsEarned' => (int) $this->coins_earned,
            'note' => $this->note,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'itemCount' => $this->whenLoaded('items', fn () => $this->items->sum('quantity')),
            'businessName' => $this->whenLoaded('business', fn () => $this->business?->name),
            'businessSlug' => $this->whenLoaded('business', fn () => $this->business?->slug),
            // Whether the customer has already reviewed this shop — drives the
            // post-order review nudge. Attached by the customer OrderController.
            'reviewed' => (bool) $this->resource->getAttribute('already_reviewed'),
            'customerName' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'placedAt' => $this->placed_at?->toIso8601String(),
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
