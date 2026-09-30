<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Razorpay payment attempt. The raw gateway payload in `meta` is deliberately
 * never exposed — it carries card/UPI detail the client has no business seeing.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'gateway' => $this->gateway,
            'orderId' => $this->gateway_order_id,
            'paymentId' => $this->gateway_payment_id,
            'status' => $this->status->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'refundedAmount' => (float) $this->refunded_amount,
            'refundableAmount' => $this->refundableAmount(),
            'errorCode' => $this->error_code,
            'errorDescription' => $this->error_description,
            'paidAt' => $this->paid_at?->toIso8601String(),
            'failedAt' => $this->failed_at?->toIso8601String(),
            'refundedAt' => $this->refunded_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            // What the money was for: a business plan, or a customer's order.
            'kind' => $this->order_id !== null ? 'order' : 'plan',
            'orderToken' => $this->whenLoaded('order', fn () => $this->order?->token),
            'orderUuid' => $this->whenLoaded('order', fn () => $this->order?->uuid),
            'customerName' => $this->whenLoaded('order', fn () => $this->order?->customer?->name),
            'planName' => $this->whenLoaded('plan', fn () => $this->plan->name),
            'businessName' => $this->whenLoaded('business', fn () => $this->business->name),
            'invoiceNumber' => $this->whenLoaded('invoice', fn () => $this->invoice?->number),
        ];
    }
}
