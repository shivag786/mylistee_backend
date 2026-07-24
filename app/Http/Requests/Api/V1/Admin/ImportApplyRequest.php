<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SPEC-011 — validates a confirmed import. `fields` carries the admin-reviewed
 * values (from the preview / comparison screen); `mode` is create | update |
 * ignore. Route middleware already enforces role:admin + active.
 */
class ImportApplyRequest extends FormRequest
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
            'placeId' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', 'string', 'in:create,update,ignore'],
            // uuid of the existing business to update / ignore.
            'businessId' => ['nullable', 'string', 'required_if:mode,update', 'required_if:mode,ignore'],

            'fields' => ['nullable', 'array'],
            'fields.name' => ['nullable', 'string', 'max:160'],
            'fields.phone' => ['nullable', 'string', 'max:32'],
            'fields.website' => ['nullable', 'string', 'max:200'],
            'fields.address' => ['nullable', 'string', 'max:255'],
            'fields.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'fields.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'fields.category' => ['nullable', 'string', 'max:120'],
            'fields.openingTime' => ['nullable', 'date_format:H:i'],
            'fields.closingTime' => ['nullable', 'date_format:H:i'],
            'fields.rating' => ['nullable', 'numeric', 'between:0,5'],
            'fields.reviewCount' => ['nullable', 'integer', 'min:0'],
            'fields.businessStatus' => ['nullable', 'string', 'max:64'],
            'fields.primaryImageUrl' => ['nullable', 'string', 'max:1024', 'url'],
            'fields.secondaryImageUrl' => ['nullable', 'string', 'max:1024', 'url'],
        ];
    }
}
