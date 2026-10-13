<?php

namespace App\Http\Requests\Api\V1\Business;

use App\Models\ShopCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a customer in the owner's customer book. The mobile number is
 * normalized before validation, so "+91 98765-43210" and "9876543210" are the
 * same customer and the per-shop uniqueness check sees through formatting.
 */
class SaveShopCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already enforces role:business_owner + active.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('phone');
        $normalized = ShopCustomer::normalizePhone(is_string($raw) ? $raw : null);

        $this->merge([
            // Keep what was typed when it can't be normalized, so the error
            // message points at the field instead of reporting it missing.
            'phone' => $normalized ?? $raw,
            'name' => $this->blankToNull('name'),
            'email' => $this->blankToNull('email'),
            'city' => $this->blankToNull('city'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business()?->id;

        return [
            'phone' => [
                'required',
                'string',
                'regex:/^[6-9]\d{9}$/',
                Rule::unique('shop_customers', 'phone')
                    ->where('business_id', $businessId)
                    ->ignore($this->route('uuid'), 'uuid'),
            ],
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.required' => 'Enter the customer\'s mobile number.',
            'phone.regex' => 'Enter a valid 10-digit mobile number.',
            'phone.unique' => 'A customer with this mobile number is already in your list.',
        ];
    }

    private function blankToNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
