<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;

class ReplyReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already enforces role:business_owner + active.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reply' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
