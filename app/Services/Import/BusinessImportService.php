<?php

namespace App\Services\Import;

use App\Enums\ImportSource;
use App\Exceptions\ImportException;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\BusinessImportLog;
use App\Models\User;
use App\Support\Import\ImportedBusinessData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SPEC-011 — orchestrates the import pipeline. Picks the right provider for a
 * URL, produces an unsaved preview (with duplicate detection), and — only when
 * the admin confirms — writes the business record and an import log.
 *
 * Nothing is persisted during preview (SPEC-011: "No data should be saved before
 * Import is clicked"). The existing Business CRUD is never touched — imports only
 * fill the google_* columns and the confirmed core fields.
 */
class BusinessImportService
{
    /** @var array<int, ImportProvider> */
    private array $providers;

    public function __construct(GoogleBusinessProvider $google)
    {
        // Future providers register here; the pipeline is provider-agnostic.
        $this->providers = [$google];
    }

    /** Resolve the first provider that recognizes the URL. */
    public function providerFor(string $url): ImportProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($url)) {
                return $provider;
            }
        }

        throw ImportException::invalidUrl();
    }

    /**
     * Fetch a preview for the URL and attach any duplicate match. No writes.
     *
     * @return array{data: ImportedBusinessData, duplicate: ?Business}
     */
    public function preview(string $url): array
    {
        $data = $this->providerFor($url)->fetch($url);

        return [
            'data' => $data,
            'duplicate' => $this->findDuplicate($data),
        ];
    }

    /**
     * Detect an existing business for this imported data — by Google Place ID
     * first (exact), then by matching name + (phone or address). Returns the
     * business to offer "Update existing" for, or null.
     */
    public function findDuplicate(ImportedBusinessData $data): ?Business
    {
        if ($data->placeId) {
            $byPlace = Business::where('google_place_id', $data->placeId)->first();
            if ($byPlace) {
                return $byPlace;
            }
        }

        if (! $data->name) {
            return null;
        }

        $slug = Str::slug($data->name);

        return Business::query()
            ->where(function ($q) use ($data, $slug): void {
                $q->where('name', $data->name)
                    ->orWhere('slug', 'like', $slug.'%');
            })
            ->when($data->phone, fn ($q) => $q->orWhere('phone', $this->digits($data->phone)))
            ->first();
    }

    /**
     * Apply a confirmed import. Creates a new (unclaimed) listing or updates the
     * chosen existing business, always stamps the google_* metadata, and writes
     * one import log. Runs in a transaction.
     *
     * @param  array<string, mixed>  $fields  Admin-confirmed core field values (camelCase).
     * @return array{business: Business, mode: string, updatedFields: array<int, string>}
     */
    public function apply(
        User $actor,
        ImportSource $source,
        string $sourceUrl,
        ?string $placeId,
        ?Business $existing,
        array $fields,
    ): array {
        return DB::transaction(function () use ($actor, $source, $sourceUrl, $placeId, $existing, $fields): array {
            $business = $existing ?? new Business();
            $creating = ! $business->exists;

            $updated = $this->applyCoreFields($business, $fields);

            // Google metadata — always stored (URLs/references only, never files).
            $business->google_business_url = $sourceUrl;
            $business->google_place_id = $placeId ?: $business->google_place_id;
            $business->google_rating = $fields['rating'] ?? $business->google_rating;
            $business->google_review_count = $fields['reviewCount'] ?? $business->google_review_count;
            $business->google_primary_image_url = $fields['primaryImageUrl'] ?? $business->google_primary_image_url;
            $business->google_secondary_image_url = $fields['secondaryImageUrl'] ?? $business->google_secondary_image_url;
            $business->google_category = $fields['category'] ?? $business->google_category;
            $business->google_imported_at = $business->google_imported_at ?? now();
            $business->google_last_sync = now();
            $business->google_sync_status = 'success';

            if ($creating) {
                // Admin-seeded, unclaimed listing (owner_id nullable). Kept
                // Active so it is immediately useful; an owner can claim it later.
                $business->owner_id = null;
                $business->created_by = $actor->id;
                $business->status = $business->status ?: 'active';
            }
            $business->updated_by = $actor->id;
            $business->save();

            BusinessImportLog::create([
                'business_id' => $business->id,
                'imported_by' => $actor->id,
                'source' => $source->value,
                'source_url' => $sourceUrl,
                'place_id' => $placeId,
                'status' => $creating ? 'created' : 'updated',
                'updated_fields' => $updated,
                'message' => count($updated).' field(s) '.($creating ? 'imported' : 'updated'),
                'ip_address' => request()?->ip(),
            ]);

            return ['business' => $business, 'mode' => $creating ? 'created' : 'updated', 'updatedFields' => $updated];
        });
    }

    /** Record an "ignored" duplicate decision for the audit trail. */
    public function logIgnored(User $actor, ImportSource $source, string $url, ?string $placeId, ?Business $business): void
    {
        BusinessImportLog::create([
            'business_id' => $business?->id,
            'imported_by' => $actor->id,
            'source' => $source->value,
            'source_url' => $url,
            'place_id' => $placeId,
            'status' => 'ignored',
            'updated_fields' => [],
            'message' => 'Duplicate ignored by admin',
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * Write the confirmed core fields onto the business, returning the list of
     * fields that actually received a value (for the success count + log).
     *
     * @param  array<string, mixed>  $fields
     * @return array<int, string>
     */
    private function applyCoreFields(Business $business, array $fields): array
    {
        $updated = [];
        $map = [
            'name' => 'name',
            'phone' => 'phone',
            'website' => 'website',
            'address' => 'address',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'openingTime' => 'opening_time',
            'closingTime' => 'closing_time',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $fields) && $fields[$input] !== null && $fields[$input] !== '') {
                $business->{$column} = $fields[$input];
                $updated[] = $input;
            }
        }

        if (! empty($fields['category'])) {
            $categoryId = $this->matchCategory((string) $fields['category']);
            if ($categoryId !== null) {
                $business->category_id = $categoryId;
                $updated[] = 'category';
            }
        }

        // Google-only fields count toward the "fields updated" total shown to admin.
        foreach (['rating', 'reviewCount', 'primaryImageUrl', 'secondaryImageUrl'] as $extra) {
            if (! empty($fields[$extra])) {
                $updated[] = $extra;
            }
        }

        return $updated;
    }

    /** Match an imported category name to an existing master category id, or null. */
    private function matchCategory(string $name): ?int
    {
        $slug = Str::slug($name);

        return BusinessCategory::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->orWhere('slug', $slug)
            ->value('id');
    }

    /** Keep only digits so phone comparison is format-insensitive. */
    private function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?: '';
    }
}
