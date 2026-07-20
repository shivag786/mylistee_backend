<?php

namespace App\Http\Resources;

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
        ];
    }
}
