<?php

namespace App\Http\Resources\Admin;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 * One row of the admin Revenue table — a single plan purchase by a business.
 */
class AdminRevenueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'businessName' => $this->business?->name,
            'businessSlug' => $this->business?->slug,
            'planName' => $this->plan?->name ?? $this->plan_name ?? '—',
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'status' => $this->status instanceof \App\Enums\SubscriptionStatus ? $this->status->value : $this->status,
            'autoRenew' => (bool) $this->auto_renew,
            // Sum of paid invoices for this subscription (populated via withSum).
            'totalPaid' => (float) ($this->total_paid ?? 0),
            'startedAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
