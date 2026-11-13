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
            // The order's number of the day at the shop (1, 2, 3...).
            'serial' => $this->serial,
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
            // Discount given at the counter.
            'manualDiscount' => (float) $this->manual_discount,
            'manualDiscountPercent' => $this->manual_discount_percent !== null ? (float) $this->manual_discount_percent : null,
            'discountReason' => $this->discount_reason,
            // The bill's GST part (null = no GST / service charge on this order).
            'tax' => $this->tax_mode !== null ? [
                'mode' => $this->tax_mode,
                'taxable' => (float) $this->taxable_amount,
                'cgst' => (float) $this->cgst,
                'sgst' => (float) $this->sgst,
                'total' => round((float) $this->cgst + (float) $this->sgst, 2),
                'serviceCharge' => (float) $this->service_charge,
                'serviceChargePercent' => (float) $this->service_charge_percent,
                'roundOff' => (float) $this->round_off,
                // Rate => [taxable, tax], for the GST summary on the bill.
                'rates' => $this->whenLoaded('items', fn () => $this->items
                    ->filter(fn ($i) => $i->tax_rate !== null)
                    ->groupBy(fn ($i) => rtrim(rtrim(number_format((float) $i->tax_rate, 2, '.', ''), '0'), '.'))
                    ->map(fn ($g, $rate) => [
                        'rate' => (float) $rate,
                        'taxable' => round((float) $g->sum('taxable_value'), 2),
                        'tax' => round((float) $g->sum('tax_amount'), 2),
                    ])->values()->all()),
            ] : null,
            'invoiceNo' => $this->invoice_no,
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
            // A walk-in order has no account: the name said at the counter.
            'customerName' => $this->customer_label ?? $this->whenLoaded('customer', fn () => $this->customer?->name),
            // 'app' = the customer ordered; 'counter' = the shop took it in person.
            'source' => $this->source ?? 'app',
            'kitchenStatus' => $this->kitchen_status?->value,
            'readyAt' => $this->ready_at?->toIso8601String(),
            'placedAt' => $this->placed_at?->toIso8601String(),
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            // Who did what (the shop's eyes only).
            $this->mergeWhen($request->routeIs('business.*'), fn () => [
                'createdBy' => $this->created_by ? $this->creator?->name : null,
                'cancelledAt' => $this->cancelled_at?->toIso8601String(),
                'cancelReason' => $this->cancel_reason,
            ]),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
