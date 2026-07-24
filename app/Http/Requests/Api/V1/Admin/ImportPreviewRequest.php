<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SPEC-011 — validates the Google Business Profile URL before a preview fetch.
 * Route middleware already enforces role:admin + active.
 */
class ImportPreviewRequest extends FormRequest
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
            'source' => ['nullable', 'string', 'in:google'],
            'url' => ['required', 'string', 'max:512', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'Please paste a Google Business Profile URL.',
            'url.url' => 'That doesn\'t look like a valid URL.',
        ];
    }
}
