<?php

namespace App\Services;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Customer-facing business discovery (document/phase/11 GET /businesses,
 * phase/02 §Nearby Discovery): search, category filter, sort, and nearby by
 * distance. Distance is computed in PHP so it works on both MySQL and the SQLite
 * test database (fine at this scale; heavy-traffic optimization is Milestone 15).
 */
class BusinessDiscoveryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Business>
     */
    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['perPage'] ?? 12), 50);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $lat = isset($filters['lat']) ? (float) $filters['lat'] : null;
        $lng = isset($filters['lng']) ? (float) $filters['lng'] : null;
        $sort = $filters['sort'] ?? 'newest';

        $query = Business::query()
            ->where('status', BusinessStatus::Active->value)
            ->with('category')
            ->withCount(['offers as offer_count' => fn ($q) => $q->live()]);

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $term));
            });
        }

        if (! empty($filters['category'])) {
            $query->whereHas('category', fn ($c) => $c->where('slug', $filters['category']));
        }

        // Recommended row: admin-verified shops only.
        if (! empty($filters['verified'])) {
            $query->where('verified', true);
        }

        // New-shops row: onboarded within the last 14 days.
        if (! empty($filters['new'])) {
            $query->where('created_at', '>=', now()->subDays(14));
        }

        $favoriteIds = $user
            ? $user->favorites()->pluck('business_id')->all()
            : [];

        $decorate = function (Business $b) use ($lat, $lng, $favoriteIds): Business {
            $b->setAttribute('distance_meters', $this->distance($b, $lat, $lng));
            $b->setAttribute('is_favorite', in_array($b->id, $favoriteIds, true));

            return $b;
        };

        // Nearest sort needs the full set ordered in PHP, then manual paging.
        if ($sort === 'nearest' && $lat !== null && $lng !== null) {
            $all = $query->get()->map($decorate)
                ->sortBy(fn (Business $b) => $b->getAttribute('distance_meters') ?? INF)
                ->values();

            return $this->paginate($all, $perPage, $page);
        }

        match ($sort) {
            'rating' => $query->orderByDesc('average_rating')->orderByDesc('total_reviews'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('featured')->latest(),
        };

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $paginator->getCollection()->transform($decorate);

        return $paginator;
    }

    /** Haversine distance in meters, or null when coordinates are missing. */
    private function distance(Business $b, ?float $lat, ?float $lng): ?int
    {
        if ($lat === null || $lng === null || $b->latitude === null || $b->longitude === null) {
            return null;
        }

        $earth = 6_371_000;
        $dLat = deg2rad((float) $b->latitude - $lat);
        $dLng = deg2rad((float) $b->longitude - $lng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat)) * cos(deg2rad((float) $b->latitude)) * sin($dLng / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * @param  Collection<int, Business>  $items
     * @return LengthAwarePaginator<int, Business>
     */
    private function paginate(Collection $items, int $perPage, int $page): LengthAwarePaginator
    {
        $slice = $items->forPage($page, $perPage)->values();

        return new Paginator($slice, $items->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }
}
