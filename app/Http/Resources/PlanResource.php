<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Services\PlanAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'interval' => $this->interval,
            // Days one purchase lasts (null = by the interval); on the free plan,
            // the free period.
            'durationDays' => $this->duration_days,
            // Or months instead of days (only one is set).
            'durationMonths' => $this->duration_months,
            // Every module with whether this plan includes it, and for how many days.
            'modules' => collect(array_keys(PlanAccessService::modules()))
                ->mapWithKeys(fn (string $id) => [$id => $this->moduleConfig($id)])
                ->all(),
            'modulesSet' => $this->modules !== null,
            // First-time offer: a lower price for a first period, open until a date.
            'intro' => $this->intro_price !== null && $this->intro_days !== null ? [
                'price' => (float) $this->intro_price,
                'days' => $this->intro_days,
                'endsAt' => $this->intro_ends_at?->toIso8601String(),
                'label' => $this->intro_label,
                'active' => $this->introActive(),
            ] : null,
            'limits' => [
                'maxActiveOffers' => $this->max_active_offers,
                'maxActiveCombos' => $this->max_active_combos,
                'maxActivePromotions' => $this->max_active_promotions,
                'maxPushPerMonth' => $this->max_push_per_month,
                'pushReachPerMonth' => $this->push_reach_per_month,
                // Notifications one customer can get from the shop a month.
                'pushPerCustomerPerMonth' => $this->push_per_customer_per_month,
                // WhatsApp offer messages a month (null = unlimited, 0 = none).
                'whatsappPerMonth' => $this->whatsapp_per_month,
                'maxOfferDays' => $this->max_offer_days,
                'maxQrCodes' => $this->max_qr_codes,
                'maxGalleryImages' => $this->max_gallery_images,
                'maxProducts' => $this->max_products,
            ],
            // Customers' email / phone on the notify screen: 'full' or 'masked'.
            'customerDetails' => $this->customer_details ?? 'masked',
            'features' => $this->features ?? [],
            'badge' => $this->badge,
            'isDefault' => $this->is_default,
            'isActive' => (bool) ($this->is_active ?? true),
            'isFree' => $this->isFree(),
            'sortOrder' => $this->sort_order,
        ];
    }
}
