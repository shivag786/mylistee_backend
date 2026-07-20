<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an upgrade/downgrade request (Milestone 13). The plan is referenced
 * by its stable `key`, never a numeric id.
 */
class SubscribeRequest extends FormRequest
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
            'planKey' => ['required', 'string', 'exists:plans,key'],
        ];
    }
}
