<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a user. Mirrors the frontend `AuthUser` type
 * (frontend/src/features/auth/types.ts). Exposes the UUID, never the numeric id.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'photoUrl' => $this->avatar_url,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'status' => $this->status->value,
            // Whether mobile + PIN sign-in works for this account. A Google-only
            // customer has none, so "change PIN" means nothing to them.
            'hasPin' => $this->pin !== null,
            // Staff: where they work and what the owner lets them do, so the
            // app shows only those screens.
            $this->mergeWhen($this->role === UserRole::Staff, fn () => [
                'staff' => $this->staffProfile ? [
                    'id' => $this->staffProfile->uuid,
                    'role' => $this->staffProfile->role,
                    'permissions' => array_values($this->staffProfile->permissions ?? []),
                    'maxDiscountPercent' => $this->staffProfile->max_discount_percent !== null ? (float) $this->staffProfile->max_discount_percent : null,
                    'phone' => $this->staffProfile->phone,
                ] : null,
            ]),
        ];
    }
}
