<?php

namespace App\Support\Import;

use App\Enums\ImportSource;

/**
 * The normalized, source-agnostic shape every ImportProvider returns (SPEC-011
 * §AUTO FILLED FIELDS). Nothing here is persisted until the admin confirms —
 * this is purely the preview payload. Read-only value object.
 */
final class ImportedBusinessData
{
    /**
     * @param  array<int, string>  $categories
     * @param  array<string, mixed>  $openingHours
     */
    public function __construct(
        public readonly ImportSource $source,
        public readonly string $sourceUrl,
        public readonly ?string $placeId = null,
        public readonly ?string $name = null,
        public readonly ?string $phone = null,
        public readonly ?string $website = null,
        public readonly ?string $address = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly array $categories = [],
        public readonly array $openingHours = [],
        public readonly ?float $rating = null,
        public readonly ?int $reviewCount = null,
        public readonly ?string $businessStatus = null,
        public readonly ?string $primaryImageUrl = null,
        public readonly ?string $secondaryImageUrl = null,
    ) {}

    /** Primary category (first) — businesses map to a single category. */
    public function primaryCategory(): ?string
    {
        return $this->categories[0] ?? null;
    }

    /**
     * Preview payload for the API/UI. camelCase to match the frontend contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'sourceLabel' => $this->source->label(),
            'sourceUrl' => $this->sourceUrl,
            'placeId' => $this->placeId,
            'name' => $this->name,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'categories' => $this->categories,
            'category' => $this->primaryCategory(),
            'openingHours' => $this->openingHours,
            'rating' => $this->rating,
            'reviewCount' => $this->reviewCount,
            'businessStatus' => $this->businessStatus,
            'primaryImageUrl' => $this->primaryImageUrl,
            'secondaryImageUrl' => $this->secondaryImageUrl,
        ];
    }
}
