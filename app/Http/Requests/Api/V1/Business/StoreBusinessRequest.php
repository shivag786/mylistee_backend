<?php

namespace App\Http\Requests\Api\V1\Business;

use App\Models\BusinessCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates business registration (document/phase/07 §Business Profile,
 * phase/11 §File Upload). API bodies are camelCase; the public category
 * identifier is a UUID, resolved to the internal id via {@see businessData()}.
 */
class StoreBusinessRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'exists:business_categories,uuid'],
            'ownerName' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'openingTime' => ['nullable', 'date_format:H:i'],
            'closingTime' => ['nullable', 'date_format:H:i'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'facebook' => ['nullable', 'url', 'max:255'],
            'instagram' => ['nullable', 'url', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'gst' => ['nullable', 'string', 'max:32'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'cover' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ];
    }

    /**
     * Validated fields mapped to model columns (camelCase → snake_case) with the
     * category UUID resolved to its internal id. Excludes file uploads.
     *
     * @return array<string, mixed>
     */
    public function businessData(): array
    {
        $categoryId = BusinessCategory::where('uuid', $this->validated('category'))->value('id');

        return array_filter([
            'name' => $this->validated('name'),
            'category_id' => $categoryId,
            'owner_name' => $this->validated('ownerName'),
            'description' => $this->validated('description'),
            'address' => $this->validated('address'),
            'latitude' => $this->validated('latitude'),
            'longitude' => $this->validated('longitude'),
            'opening_time' => $this->validated('openingTime'),
            'closing_time' => $this->validated('closingTime'),
            'phone' => $this->validated('phone'),
            'email' => $this->validated('email'),
            'website' => $this->validated('website'),
            'facebook' => $this->validated('facebook'),
            'instagram' => $this->validated('instagram'),
            'whatsapp' => $this->validated('whatsapp'),
            'gst' => $this->validated('gst'),
        ], fn ($value) => $value !== null);
    }
}
