<?php

namespace App\Http\Resources;

use App\Models\Plan;
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
            'limits' => [
                'maxActiveOffers' => $this->max_active_offers,
                'maxOfferDays' => $this->max_offer_days,
                'maxQrCodes' => $this->max_qr_codes,
                'maxGalleryImages' => $this->max_gallery_images,
            ],
            'features' => $this->features ?? [],
            'badge' => $this->badge,
            'isDefault' => $this->is_default,
            'isFree' => $this->isFree(),
            'sortOrder' => $this->sort_order,
        ];
    }
}
