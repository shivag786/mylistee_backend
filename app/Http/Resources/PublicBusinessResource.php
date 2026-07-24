<?php

namespace App\Http\Resources;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public business profile shown when a customer scans the QR (document/phase/06,
 * phase/11 GET /business/{slug}). Owner-only fields (email, gst, counters) are
 * omitted. `liveOffers` (relation) becomes the spinner segments.
 *
 * @mixin Business
 */
class PublicBusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category?->name,
            'logoUrl' => $this->url($this->logo_path),
            'coverUrl' => $this->url($this->cover_path),
            'address' => $this->address,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'openingTime' => $this->opening_time,
            'closingTime' => $this->closing_time,
            'isOpen' => $this->isOpenNow(),
            'phone' => $this->phone,
            'website' => $this->website,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'whatsapp' => $this->whatsapp,
            'averageRating' => (float) $this->average_rating,
            'totalReviews' => $this->total_reviews,
            'gallery' => GalleryImageResource::collection($this->whenLoaded('gallery')),
            'offers' => PublicOfferResource::collection($this->whenLoaded('liveOffers')),
            'menu' => $this->buildMenu(),
            'combos' => ComboResource::collection($this->whenLoaded('combos')),
        ];
    }

    /**
     * Group visible products into their menu sections for the customer menu
     * (Phase 7.4). Uncategorised products fall into a default "Menu" section.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMenu(): array
    {
        if (! $this->relationLoaded('products')) {
            return [];
        }

        $byCategory = $this->products->groupBy('product_category_id');
        $sections = [];

        if ($this->relationLoaded('productCategories')) {
            foreach ($this->productCategories as $category) {
                $items = $byCategory->get($category->id);
                if ($items && $items->isNotEmpty()) {
                    $sections[] = [
                        'id' => $category->uuid,
                        'name' => $category->name,
                        'products' => ProductResource::collection($items),
                    ];
                }
            }
        }

        $uncategorised = $byCategory->get(null);
        if ($uncategorised && $uncategorised->isNotEmpty()) {
            $sections[] = [
                'id' => 'general',
                'name' => 'Menu',
                'products' => ProductResource::collection($uncategorised),
            ];
        }

        return $sections;
    }

    private function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
