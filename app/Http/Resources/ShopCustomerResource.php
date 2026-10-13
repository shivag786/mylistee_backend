<?php

namespace App\Http\Resources;

use App\Models\ShopCustomer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShopCustomer
 */
class ShopCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'phone' => $this->phone,
            'name' => $this->name,
            'email' => $this->email,
            'city' => $this->city,
            'source' => $this->source,
            // Set by withCount('reviews') on the list endpoint.
            'reviewsCount' => (int) ($this->reviews_count ?? 0),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
