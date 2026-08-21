<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The handshake Razorpay Checkout hands back to the browser. Every field is
 * attacker-controlled — validation here only checks shape; authenticity is
 * proven by the HMAC check in
 * {@see \App\Services\SubscriptionPaymentService::verify()}.
 */
class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route already guards role:business_owner
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'razorpayOrderId' => ['required', 'string', 'max:64'],
            'razorpayPaymentId' => ['required', 'string', 'max:64'],
            'razorpaySignature' => ['required', 'string', 'max:255'],
        ];
    }
}
