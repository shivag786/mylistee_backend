<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Orders the customer home feeds (deals, combos, …) for one viewer: shops they
 * follow first, then shops near them, then the better offer. A few rules keep
 * one busy shop from taking over a row:
 *  - at most `perShop` items from any one shop;
 *  - two items from the same shop never sit side by side when it can be helped.
 *
 * Location and the follow list are both optional — a signed-out visitor with
 * location off still gets a sensible, mixed row.
 */
final class FeedRanker
{
    /** Score bonus for a shop the viewer follows — outranks any distance or discount. */
    private const FOLLOWED = 100;

    /** @param  array<int, true>  $followed  business ids the viewer follows */
    public function __construct(
        private readonly ?float $lat,
        private readonly ?float $lng,
        private readonly array $followed = [],
    ) {}

    /** Reads `lat`/`lng` from the query and the follow list of the signed-in viewer, if any. */
    public static function fromRequest(Request $request): self
    {
        $lat = is_numeric($request->query('lat')) ? (float) $request->query('lat') : null;
        $lng = is_numeric($request->query('lng')) ? (float) $request->query('lng') : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $lat = null;
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $lng = null;
        }

        // Public route: the token is optional, so resolve it without requiring auth.
        $user = $request->user('sanctum');
        $followed = $user === null ? [] : Favorite::query()
            ->where('customer_id', $user->id)
            ->pluck('business_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        return new self($lat, $lng, $followed);
    }

    public function isFollowed(Business $business): bool
    {
        return isset($this->followed[$business->id]);
    }

    /** Haversine distance in meters, or null when either side has no coordinates. */
    public function distance(Business $business): ?int
    {
        if ($this->lat === null || $this->lng === null || $business->latitude === null || $business->longitude === null) {
            return null;
        }

        $earth = 6_371_000;
        $dLat = deg2rad((float) $business->latitude - $this->lat);
        $dLng = deg2rad((float) $business->longitude - $this->lng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->lat)) * cos(deg2rad((float) $business->latitude)) * sin($dLng / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** How much the shop itself is worth to this viewer: followed, then near. */
    public function shopScore(Business $business): float
    {
        $score = $this->isFollowed($business) ? self::FOLLOWED : 0;

        $meters = $this->distance($business);
        $score += match (true) {
            $meters === null => 0,
            $meters <= 1_000 => 40,
            $meters <= 3_000 => 28,
            $meters <= 7_000 => 16,
            $meters <= 15_000 => 6,
            default => 0,
        };

        return $score;
    }

    /**
     * Rank and pick.
     *
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @param  callable(T): Business  $businessOf
     * @param  callable(T): float  $offerScore  0–40ish: how good the offer itself is
     * @return Collection<int, T>
     */
    public function rank(Collection $items, callable $businessOf, callable $offerScore, int $limit, int $perShop = 2): Collection
    {
        $scored = $items
            ->map(fn ($item) => ['item' => $item, 'shop' => $businessOf($item)->id, 'score' => $this->shopScore($businessOf($item)) + $offerScore($item)])
            ->sortByDesc('score')
            ->values();

        // Cap each shop.
        $perShopCount = [];
        $picked = [];
        foreach ($scored as $row) {
            $count = $perShopCount[$row['shop']] ?? 0;
            if ($count >= $perShop) {
                continue;
            }
            $perShopCount[$row['shop']] = $count + 1;
            $picked[] = $row;
            if (count($picked) >= $limit) {
                break;
            }
        }

        // Keep the score order, but never two from one shop in a row if another
        // shop's item can go between them.
        $ordered = [];
        $pending = $picked;
        while ($pending !== []) {
            $last = $ordered === [] ? null : end($ordered)['shop'];
            $index = 0;
            foreach ($pending as $i => $row) {
                if ($row['shop'] !== $last) {
                    $index = $i;
                    break;
                }
            }
            $ordered[] = $pending[$index];
            array_splice($pending, $index, 1);
        }

        return collect($ordered)->map(function (array $row) use ($businessOf) {
            $business = $businessOf($row['item']);
            // Read by the feed resources for the "Following" / "1.2 km" chips.
            $row['item']->setAttribute('feed_distance_meters', $this->distance($business));
            $row['item']->setAttribute('feed_is_followed', $this->isFollowed($business));

            return $row['item'];
        })->values();
    }
}
