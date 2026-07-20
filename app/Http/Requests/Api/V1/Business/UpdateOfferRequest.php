<?php

namespace App\Http\Requests\Api\V1\Business;

use App\Enums\OfferType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an offer edit. Every field optional — only present keys are applied.
 */
class UpdateOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['sometimes', 'required', Rule::enum(OfferType::class)],
            'rewardValue' => ['nullable', 'string', 'max:255'],
            'startsAt' => ['sometimes', 'required', 'date'],
            'endsAt' => ['sometimes', 'required', 'date', 'after_or_equal:startsAt'],
            'totalQuantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'visibility' => ['nullable', Rule::in(['public', 'hidden'])],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function offerData(): array
    {
        $map = [
            'title' => 'title',
            'description' => 'description',
            'type' => 'type',
            'rewardValue' => 'reward_value',
            'startsAt' => 'starts_at',
            'endsAt' => 'ends_at',
            'totalQuantity' => 'total_quantity',
            'weight' => 'weight',
            'priority' => 'priority',
            'visibility' => 'visibility',
        ];

        $data = [];
        foreach ($map as $input => $column) {
            if ($this->has($input)) {
                $data[$column] = $this->input($input);
            }
        }

        return $data;
    }
}
