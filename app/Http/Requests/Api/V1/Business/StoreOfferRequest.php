<?php

namespace App\Http\Requests\Api\V1\Business;

use App\Enums\OfferType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates offer creation (document/phase/07 §Offer Management, phase/11
 * §Offer Endpoints). Plan limits (active count / validity length) are enforced
 * in OfferService, not here.
 */
class StoreOfferRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::enum(OfferType::class)],
            'rewardValue' => ['nullable', 'string', 'max:255'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after_or_equal:startsAt'],
            'totalQuantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'visibility' => ['nullable', Rule::in(['public', 'hidden'])],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ];
    }

    /**
     * Validated fields mapped to model columns (camelCase → snake_case).
     *
     * @return array<string, mixed>
     */
    public function offerData(): array
    {
        return array_filter([
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
            'type' => $this->validated('type'),
            'reward_value' => $this->validated('rewardValue'),
            'starts_at' => $this->validated('startsAt'),
            'ends_at' => $this->validated('endsAt'),
            'total_quantity' => $this->validated('totalQuantity'),
            'weight' => $this->validated('weight') ?? 1,
            'priority' => $this->validated('priority') ?? 0,
            'visibility' => $this->validated('visibility') ?? 'public',
        ], fn ($v) => $v !== null);
    }
}
