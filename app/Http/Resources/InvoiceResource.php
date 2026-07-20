<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'planName' => $this->plan_name,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'periodStart' => $this->period_start?->toDateString(),
            'periodEnd' => $this->period_end?->toDateString(),
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
        ];
    }
}
