<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A failure the browser reported from Checkout. Advisory only — it can never
 * activate or block a plan, so the fields are recorded, not trusted.
 */
class PaymentFailedRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
