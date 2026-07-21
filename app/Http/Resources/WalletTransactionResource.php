<?php

namespace App\Http\Resources;

use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WalletTransaction
 */
class WalletTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'source' => $this->source->value,
            'amount' => $this->amount,
            'balanceAfter' => $this->balance_after,
            'description' => $this->description ?? $this->source->label(),
            'businessName' => $this->whenLoaded('business', fn () => $this->business?->name),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
