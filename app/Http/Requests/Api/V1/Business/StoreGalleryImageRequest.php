<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;

class StoreGalleryImageRequest extends FormRequest
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
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ];
    }
}
