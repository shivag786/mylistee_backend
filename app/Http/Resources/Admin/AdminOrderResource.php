<?php

namespace App\Http\Resources\Admin;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the Super Admin sees it: across every shop, with the customer
 * and the money trail side by side, so "I paid and nothing happened" can be
 * answered from one row.
 *
 * @mixin Order
 */
class AdminOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->relationLoaded('latestPayment') ? $this->latestPayment : null;

        return [
            'id' => $this->uuid,
            'token' => $this->token,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'businessName' => $this->business?->name,
            'businessSlug' => $this->business?->slug,
            'customerName' => $this->customer?->name,
            // A mobile sign-up has no email, a Google one usually no phone.
            'customerContact' => $this->customer?->phone ?: $this->customer?->email,
            'serviceLabel' => $this->serviceLabel(),
            'itemCount' => $this->relationLoaded('items') ? (int) $this->items->sum('quantity') : null,
            'total' => (float) $this->total,

            // How it was paid, and where that stands.
            'paymentType' => $this->paymentType(),
            'onlineAmount' => (float) $this->online_amount,
            'convenienceFee' => (float) $this->convenience_fee,
            'paidOnline' => $this->isPaidOnline(),
            'amountDue' => $this->amountDue(),
            'payment' => $payment === null ? null : [
                'id' => $payment->uuid,
                'status' => $payment->status->value,
                'gatewayPaymentId' => $payment->gateway_payment_id,
                'gatewayOrderId' => $payment->gateway_order_id,
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'refundedAmount' => (float) $payment->refunded_amount,
                'errorDescription' => $payment->error_description,
            ],

            'placedAt' => $this->placed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
