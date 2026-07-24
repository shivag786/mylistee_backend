<?php

namespace App\Services\Import;

use App\Enums\ImportSource;
use App\Exceptions\ImportException;
use App\Support\Import\ImportedBusinessData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * SPEC-011 — Google Business Profile importer. Validates the URL, extracts a
 * Place ID, and fetches public details via the Google Places API (Place Details
 * v1) when a key is configured.
 *
 * When no key is present AND the app is not in production, it returns a
 * deterministic sandbox payload derived from the URL so the whole flow (preview,
 * comparison, import, logs, duplicate detection) can be exercised in dev — the
 * same "never hard-fail without credentials" philosophy as AiOfferSuggestionService.
 */
class GoogleBusinessProvider implements ImportProvider
{
    private const DETAILS_ENDPOINT = 'https://places.googleapis.com/v1/places/';

    /** Host fragments that identify a Google Maps / Business link. */
    private const GOOGLE_HOSTS = ['google.', 'goo.gl', 'g.co', 'g.page', 'maps.app.goo.gl'];

    public function source(): ImportSource
    {
        return ImportSource::Google;
    }

    public function supports(string $url): bool
    {
        $host = Str::lower((string) parse_url(trim($url), PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::GOOGLE_HOSTS as $fragment) {
            if (str_contains($host, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function fetch(string $url): ImportedBusinessData
    {
        $url = trim($url);

        if (! $this->isValidUrl($url) || ! $this->supports($url)) {
            throw ImportException::invalidUrl();
        }

        $placeId = $this->extractPlaceId($url);

        if (! $this->isConfigured()) {
            if (App::environment('production')) {
                throw ImportException::notConfigured();
            }

            return $this->sandbox($url, $placeId);
        }

        if ($placeId === null) {
            // Without a key we can't resolve short links to a Place ID.
            throw ImportException::notFound();
        }

        return $this->fetchFromApi($url, $placeId);
    }

    /** Whether a Google Places API key is available. */
    public function isConfigured(): bool
    {
        return filled(config('services.google.places_key'));
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && Str::startsWith(Str::lower($url), ['http://', 'https://']);
    }

    /**
     * Best-effort Place ID extraction from the common Google URL shapes:
     *  - ...?place_id=ChIJ...  / ...?q=place_id:ChIJ...
     *  - .../place/.../data=...!1s0x...:0x...  (hex CID pairs → not a Place ID)
     *  - .../maps/place/Name/@lat,lng,...
     *  - a bare ChIJ... token anywhere in the URL
     * Short links (maps.app.goo.gl, g.page) can't be resolved offline; returns null.
     */
    public function extractPlaceId(string $url): ?string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        foreach (['place_id', 'placeid'] as $key) {
            if (! empty($query[$key]) && is_string($query[$key])) {
                return $query[$key];
            }
        }

        if (! empty($query['q']) && is_string($query['q']) && preg_match('/place_id:([\w-]+)/', $query['q'], $m)) {
            return $m[1];
        }

        // A Place ID token (starts with ChIJ / GhIJ, base64url-ish) anywhere.
        if (preg_match('/\b((?:ChIJ|GhIJ|EhIJ|EiIJ)[\w-]{10,})/', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private function fetchFromApi(string $url, string $placeId): ImportedBusinessData
    {
        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => (string) config('services.google.places_key'),
                'X-Goog-FieldMask' => implode(',', [
                    'id', 'displayName', 'formattedAddress', 'internationalPhoneNumber',
                    'nationalPhoneNumber', 'websiteUri', 'rating', 'userRatingCount',
                    'businessStatus', 'location', 'primaryTypeDisplayName', 'types',
                    'regularOpeningHours', 'photos', 'googleMapsUri',
                ]),
            ])->timeout(20)->get(self::DETAILS_ENDPOINT.$placeId);

            if ($response->status() === 429) {
                throw ImportException::rateLimit();
            }
            if ($response->status() === 404) {
                throw ImportException::notFound();
            }
            if (! $response->successful()) {
                Log::warning('Google import API error', ['status' => $response->status(), 'place' => $placeId]);
                throw ImportException::apiError();
            }

            return $this->mapApiResponse($url, $response->json());
        } catch (ImportException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw ImportException::timeout();
        } catch (Throwable $e) {
            Log::warning('Google import failed', ['error' => $e->getMessage(), 'place' => $placeId]);
            throw ImportException::apiError();
        }
    }

    /**
     * Map a Places API v1 payload to the normalized DTO. Photos become the
     * Places photo-media URL (a reference/URL only — never downloaded).
     *
     * @param  array<string, mixed>  $data
     */
    private function mapApiResponse(string $url, array $data): ImportedBusinessData
    {
        $photos = is_array($data['photos'] ?? null) ? $data['photos'] : [];
        $key = (string) config('services.google.places_key');
        $photoUrl = function (int $i) use ($photos, $key): ?string {
            $name = $photos[$i]['name'] ?? null;

            return is_string($name)
                ? 'https://places.googleapis.com/v1/'.$name.'/media?maxWidthPx=1200&key='.$key
                : null;
        };

        $types = is_array($data['types'] ?? null) ? $data['types'] : [];
        $primaryType = data_get($data, 'primaryTypeDisplayName.text');
        $categories = array_values(array_filter(array_merge(
            $primaryType ? [$primaryType] : [],
            array_map(fn ($t) => Str::headline((string) $t), $types),
        )));

        return new ImportedBusinessData(
            source: ImportSource::Google,
            sourceUrl: (string) (data_get($data, 'googleMapsUri') ?: $url),
            placeId: data_get($data, 'id'),
            name: data_get($data, 'displayName.text'),
            phone: data_get($data, 'internationalPhoneNumber') ?? data_get($data, 'nationalPhoneNumber'),
            website: data_get($data, 'websiteUri'),
            address: data_get($data, 'formattedAddress'),
            latitude: ($lat = data_get($data, 'location.latitude')) !== null ? (float) $lat : null,
            longitude: ($lng = data_get($data, 'location.longitude')) !== null ? (float) $lng : null,
            categories: array_slice(array_unique($categories), 0, 5),
            openingHours: is_array(data_get($data, 'regularOpeningHours.weekdayDescriptions'))
                ? ['weekdayDescriptions' => data_get($data, 'regularOpeningHours.weekdayDescriptions')]
                : [],
            rating: ($r = data_get($data, 'rating')) !== null ? (float) $r : null,
            reviewCount: ($c = data_get($data, 'userRatingCount')) !== null ? (int) $c : null,
            businessStatus: data_get($data, 'businessStatus'),
            primaryImageUrl: $photoUrl(0),
            secondaryImageUrl: $photoUrl(1),
        );
    }

    /**
     * Deterministic dev preview derived from the URL (non-production only). Lets
     * the full import flow be demoed/tested without a Google key. Clearly marked
     * so it's never mistaken for live data.
     */
    private function sandbox(string $url, ?string $placeId): ImportedBusinessData
    {
        $seed = crc32($url);
        $name = $this->sandboxName($url, $seed);
        $slug = Str::slug($name);

        return new ImportedBusinessData(
            source: ImportSource::Google,
            sourceUrl: $url,
            placeId: $placeId ?? 'ChIJ'.strtoupper(substr(md5($url), 0, 16)),
            name: $name,
            phone: '+91 '.str_pad((string) (($seed % 90000) + 10000), 5, '0', STR_PAD_LEFT).' '.str_pad((string) ($seed % 100000), 5, '0', STR_PAD_LEFT),
            website: 'https://'.$slug.'.example.com',
            address: (($seed % 90) + 10).' Market Road, Sector '.(($seed % 40) + 1).', India',
            latitude: round(19.0 + (($seed % 1000) / 1000), 6),
            longitude: round(72.8 + (($seed % 1000) / 1000), 6),
            categories: [['Restaurant', 'Cafe', 'Salon', 'Grocery Store', 'Pharmacy'][$seed % 5]],
            openingHours: ['weekdayDescriptions' => [
                'Monday: 9:00 AM – 9:00 PM', 'Tuesday: 9:00 AM – 9:00 PM',
                'Wednesday: 9:00 AM – 9:00 PM', 'Thursday: 9:00 AM – 9:00 PM',
                'Friday: 9:00 AM – 10:00 PM', 'Saturday: 9:00 AM – 10:00 PM',
                'Sunday: 10:00 AM – 8:00 PM',
            ]],
            rating: round(3.5 + (($seed % 15) / 10), 1),
            reviewCount: ($seed % 900) + 20,
            businessStatus: 'OPERATIONAL',
            primaryImageUrl: 'https://picsum.photos/seed/'.$slug.'-1/1200/800',
            secondaryImageUrl: 'https://picsum.photos/seed/'.$slug.'-2/1200/800',
        );
    }

    private function sandboxName(string $url, int $seed): string
    {
        // Reuse a name embedded in a /place/<Name>/ URL when present.
        if (preg_match('#/place/([^/@]+)#', $url, $m)) {
            $decoded = trim(str_replace('+', ' ', urldecode($m[1])));
            if ($decoded !== '') {
                return Str::headline($decoded);
            }
        }

        $prefixes = ['Sunrise', 'Green Leaf', 'Royal', 'Urban', 'Golden', 'Blue Sky', 'Star'];
        $suffixes = ['Cafe', 'Kitchen', 'Mart', 'Studio', 'Bakery', 'Corner', 'House'];

        return $prefixes[$seed % count($prefixes)].' '.$suffixes[($seed >> 3) % count($suffixes)];
    }
}
