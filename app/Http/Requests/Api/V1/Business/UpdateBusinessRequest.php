<?php

namespace App\Http\Requests\Api\V1\Business;

use App\Models\BusinessCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a business profile edit (document/phase/07 §Business Settings).
 * Every field is optional — only the keys actually present are applied, so the
 * client can send partial updates.
 */
class UpdateBusinessRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'exists:business_categories,uuid'],
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
     * Only the present fields, mapped to model columns. Category UUID (when sent)
     * is resolved to its internal id.
     *
     * @return array<string, mixed>
     */
    public function businessData(): array
    {
        $map = [
            'name' => 'name',
            'ownerName' => 'owner_name',
            'description' => 'description',
            'address' => 'address',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'openingTime' => 'opening_time',
            'closingTime' => 'closing_time',
            'phone' => 'phone',
            'email' => 'email',
            'website' => 'website',
            'facebook' => 'facebook',
            'instagram' => 'instagram',
            'whatsapp' => 'whatsapp',
            'gst' => 'gst',
        ];

        $data = [];
        foreach ($map as $input => $column) {
            if ($this->has($input)) {
                $data[$column] = $this->input($input);
            }
        }

        if ($this->has('category')) {
            $data['category_id'] = BusinessCategory::where('uuid', $this->input('category'))->value('id');
        }

        return $data;
    }
}
