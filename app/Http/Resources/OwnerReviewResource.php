<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;

/**
 * A review as the shop's owner sees it: the public fields plus the reviewer's
 * mobile (review-link reviews) and whether it's hidden from the public page.
 *
 * @mixin Review
 */
class OwnerReviewResource extends ReviewResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'phone' => $this->shopCustomer?->phone,
            // 'link' = left through the shop's review link; 'app' = signed-in customer.
            'source' => $this->customer_id === null ? 'link' : 'app',
            'isHidden' => $this->hidden_by_owner_at !== null,
            // An admin removed it; the owner can't bring it back.
            'isRemovedByAdmin' => $this->status !== 'published',
        ];
    }
}
