<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminCustomerResource extends JsonResource
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
            'avatarUrl' => $this->avatar_url,
            'phone' => $this->phone,
            'role' => $this->role instanceof \App\Enums\UserRole ? $this->role->value : $this->role,
            'status' => $this->status instanceof \App\Enums\UserStatus ? $this->status->value : $this->status,
            'spins' => (int) ($this->spins_count ?? 0),
            'rewards' => (int) ($this->rewards_count ?? 0),
            'createdAt' => $this->created_at?->toIso8601String(),
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
